<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\MediaServers\Contracts\CreatesUsers;
use App\MediaServers\Contracts\UsesClaimLinks;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * What members may do about their own access, when the admin allows it:
 * pause it for a while (the end date moves by the time paused), and move
 * from one server to another.
 */
class SelfService
{
    public static function maxPauseDays(): int
    {
        return max(0, min(365, (int) app(SiteSettings::class)->get('self_pause_days')));
    }

    public function canPause(User $member): bool
    {
        return self::maxPauseDays() > 0 && $member->self_paused_at === null && $member->disabled_at === null && $member->hasActiveAccess();
    }

    public function pause(User $member, int $days): void
    {
        if (! $this->canPause($member) || $days < 1 || $days > self::maxPauseDays()) {
            throw new RuntimeException(__('You can\'t pause right now.'));
        }
        $failed = app(AccessManager::class)->disableAll($member, 'paused by the member');
        $member->forceFill(['self_paused_at' => now(), 'self_pause_until' => now()->addDays($days)])->save();
        Audit::log('member.self_paused', $member, ['days' => $days] + ($failed !== [] ? ['failed' => $failed] : []), $member->id);
    }

    /** Lets them back in; the time paused is added to their end date. */
    public function resume(User $member): void
    {
        if ($member->self_paused_at === null) {
            return;
        }
        $paused = (int) max(0, $member->self_paused_at->diffInSeconds(now()));
        $failed = $member->disabled_at === null ? app(AccessManager::class)->enableAll($member) : [];
        $member->forceFill([
            'self_paused_at' => null, 'self_pause_until' => null,
            'access_expires_at' => $member->access_expires_at?->copy()->addSeconds($paused),
        ])->save();
        Audit::log('member.self_resumed', $member, $failed !== [] ? ['failed' => $failed] : [], $member->id);
    }

    /** @return list<int> the servers members may move to */
    public static function switchTargets(): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) app(SiteSettings::class)->get('switch_servers')))));
    }

    /** @return Collection<int, MediaServer> where this member may move to now */
    public function targetsFor(User $member): Collection
    {
        $have = $member->serverAccounts()->pluck('media_server_id')->all();

        return MediaServer::whereIn('id', self::switchTargets())->where('is_enabled', true)->whereNotIn('id', $have)->orderBy('name')->get()
            ->filter(fn (MediaServer $s) => ($s->type->can(CreatesUsers::class) || $s->type->can(UsesClaimLinks::class)) && ! Waitlist::isFull($s))->values();
    }

    /**
     * Moves the member from one of their servers to another: the new user
     * first (all libraries), then the old one is removed. On Silo they
     * choose the new user on Silo's own page.
     */
    public function move(User $member, ServerAccount $from, MediaServer $to, ?string $password): ServerAccount
    {
        if ($from->user_id !== $member->id || $from->status !== AccountStatus::Active || ! $this->targetsFor($member)->contains('id', $to->id)) {
            throw new RuntimeException(__('You can\'t move there right now.'));
        }
        $driver = $to->driver();
        if ($driver instanceof CreatesUsers) {
            if (blank($password)) {
                throw new RuntimeException(__('Choose a password for :server.', ['server' => $to->name]));
            }
            $remote = $driver->createUser((string) ($from->remote_username ?: $member->name), (string) $password, null);
            $new = ServerAccount::create(['user_id' => $member->id, 'media_server_id' => $to->id, 'invitation_id' => $from->invitation_id,
                'remote_id' => $remote->id, 'remote_username' => $remote->username, 'status' => AccountStatus::Active]);
        } elseif ($driver instanceof UsesClaimLinks) {
            $claim = $driver->createClaim(null);
            $new = ServerAccount::create(['user_id' => $member->id, 'media_server_id' => $to->id, 'invitation_id' => $from->invitation_id,
                'status' => AccountStatus::Pending, 'claim_url' => $claim->url, 'claim_id' => $claim->id]);
        } else {
            throw new RuntimeException(__('You can\'t move there right now.'));
        }
        Audit::log('member.moved_server', $member, ['from' => $from->mediaServer->name, 'to' => $to->name], $member->id);
        try {
            app(AccessManager::class)->removeOne($from);
        } catch (Throwable $e) {
            report($e);
            Audit::log('member.move_old_left', $member, ['server' => $from->mediaServer->name], $member->id);
        }

        return $new;
    }
}
