<?php

namespace App\Services;

use App\MediaServers\Data\PlexIdentity;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\Welcome;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Redeeming an invitation: reserve a use (under a row lock, so two people
 * can't take the last one), make the users on the servers, then save the
 * account. A failure gives the use back and removes what was made.
 */
class Redeemer
{
    public function __construct(private AccessManager $access) {}

    /**
     * @param  array{name: string, email: string, password: string}|null  $newAccount  null when $existing redeems
     * @param  array{identity: PlexIdentity, token: string}|null  $plex
     */
    public function redeem(Invitation $invitation, ?User $existing, ?array $newAccount, string $username, ?string $serverPassword, ?array $plex): User
    {
        $this->reserve($invitation);
        $user = $existing ?? new User(['name' => $newAccount['name'], 'email' => $newAccount['email'], 'password' => $newAccount['password']]);

        try {
            $invitation->load('mediaServers');
            $made = $this->access->provision($user, $invitation, $username, $serverPassword, $plex);
        } catch (Throwable $e) {
            $this->release($invitation);

            throw $e;
        }

        try {
            DB::transaction(function () use ($user, $invitation, $made) {
                if ($invitation->access_days !== null) {
                    $until = now()->addDays($invitation->access_days);
                    // Never shortens access the member already has.
                    if (! $user->exists || ($user->access_expires_at !== null && $user->access_expires_at->lt($until))) {
                        $user->access_expires_at = $until;
                        $user->expiry_warned_at = null;
                    }
                } elseif ($user->exists) {
                    $user->access_expires_at = null;
                }
                $user->save();
                $this->access->record($user, $invitation, $made);
            });
        } catch (Throwable $e) {
            $this->access->undo($made);
            $this->release($invitation);

            throw $e;
        }

        Audit::log('invitation.redeemed', $invitation, ['servers' => array_map(fn ($r) => $r['server']->name, $made)], $user->id);
        if ($existing === null) {
            try {
                $user->notify(new Welcome);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $user;
    }

    private function reserve(Invitation $invitation): void
    {
        DB::transaction(function () use ($invitation) {
            $locked = Invitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if (($problem = $locked->problem()) !== null) {
                throw new InvitationProblem($problem);
            }
            $locked->increment('uses');
        });
    }

    private function release(Invitation $invitation): void
    {
        Invitation::whereKey($invitation->id)->where('uses', '>', 0)->decrement('uses');
    }
}
