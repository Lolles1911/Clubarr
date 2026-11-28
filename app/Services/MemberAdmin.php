<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * What an admin does to a member, for one or many at once: pause, let back
 * in, change when access ends, remove. Returns the servers that failed.
 */
class MemberAdmin
{
    public function __construct(private AccessManager $access, private DiscordSync $discord) {}

    /** @return list<string> */
    public function disable(User $user): array
    {
        $user->forceFill(['disabled_at' => now()])->save();
        Audit::log('member.disabled', $user);
        $failed = $this->access->disableAll($user, 'disabled by admin');
        $this->discord->sync($user);

        return $failed;
    }

    /** @return list<string> */
    public function enable(User $user): array
    {
        $user->forceFill(['disabled_at' => null])->save();
        Audit::log('member.enabled', $user);
        $failed = $user->hasAccessExpired() ? [] : $this->access->enableAll($user);
        $this->discord->sync($user);

        return $failed;
    }

    /** @return list<string> */
    public function setEnd(User $user, ?Carbon $until): array
    {
        $user->forceFill(['access_expires_at' => $until, 'expiry_warned_at' => null])->save();
        Audit::log('member.access_changed', $user, ['until' => $until?->toIso8601String()]);

        return ! $user->hasAccessExpired() && $user->disabled_at === null ? $this->access->enableAll($user) : [];
    }

    /** Removes the member's users everywhere and then the member; kept when a server failed. @return list<string> */
    public function remove(User $user): array
    {
        $failed = $this->access->removeAll($user);
        $this->discord->sync($user);
        if ($failed === []) {
            Audit::log('member.removed', null, ['email' => $user->email]);
            $user->delete();
        }

        return $failed;
    }
}
