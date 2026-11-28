<?php

namespace App\Services;

use App\MediaServers\Data\PlexIdentity;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationUsed;
use App\Notifications\Welcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        self::checkSeats($invitation, $existing);
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
                // The invitation's limits; a member keeps the more generous one (null = none).
                $user->max_streams = self::generous($user->exists ? $user->max_streams : 0, $invitation->max_streams, $user->exists);
                $user->request_limit = self::generous($user->exists ? $user->request_limit : 0, $invitation->request_limit, $user->exists);
                // Requests only when the invitation gives them (a member keeps them once given).
                $user->seerr_access = ($user->exists && $user->seerr_access) || $invitation->seerr_access;
                $user->save();
                $this->access->record($user, $invitation, $made);
            });
        } catch (Throwable $e) {
            $this->access->undo($made);
            $this->release($invitation);

            throw $e;
        }

        Audit::log('invitation.redeemed', $invitation, ['servers' => array_map(fn ($r) => $r['server']->name, $made)], $user->id);
        if ($invitation->member_group_id !== null) {
            $user->groups()->syncWithoutDetaching([$invitation->member_group_id]);
            app(GroupRules::class)->apply($user);
        }
        // The link kept for a reminder is not needed any more.
        $invitation->forceFill(['reminder_link' => null])->save();
        $this->tellCreator($invitation, $user);
        app(DiscordSync::class)->sync($user);
        // Their own user in Overseerr/Jellyseerr right away (on the queue: Seerr can be slow).
        if ($user->seerr_access && app(Requests::class)->enabled()) {
            $userId = $user->id;
            dispatch(function () use ($userId) {
                $member = User::find($userId);
                try {
                    $member && app(Requests::class)->seerrUser($member);
                } catch (Throwable $e) {
                    Log::info('Clubarr: no Seerr user yet for user '.$userId.': '.class_basename($e));
                }
            });
        }
        if ($existing === null) {
            try {
                $user->notify(new Welcome);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $user;
    }

    /** Mails the one who made the invitation (admin or member) that it was used. */
    private function tellCreator(Invitation $invitation, User $user): void
    {
        $creator = $invitation->creator;
        if ($creator === null || $creator->id === $user->id || $creator->disabled_at !== null || app(SiteSettings::class)->get('mail_enabled') !== '1') {
            return;
        }
        try {
            $creator->notify(new InvitationUsed($user->name, $creator->is_admin ? $invitation->label : null));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Throws when a server the visitor doesn't have yet is full (waiting-list invitations hold their seat). */
    public static function checkSeats(Invitation $invitation, ?User $existing): void
    {
        if ($invitation->from_waitlist) {
            return;
        }
        $have = $existing?->serverAccounts()->pluck('media_server_id')->all() ?? [];
        foreach ($invitation->mediaServers as $server) {
            if (! in_array($server->id, $have, true) && Waitlist::isFull($server)) {
                throw new InvitationProblem(__(':server is full right now.', ['server' => $server->name]), full: true);
            }
        }
    }

    /** The more generous of two limits; null means no limit. A new member just gets the invitation's. */
    private static function generous(?int $current, ?int $offered, bool $existing): ?int
    {
        if (! $existing || $current === null || $offered === null) {
            return $existing && $current === null ? null : $offered;
        }

        return max($current, $offered);
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
