<?php

namespace App\Services;

use App\Models\MemberGroup;
use App\Models\User;

/**
 * Applies the rules of a member's groups: no end to access, streams at the
 * same time, requests per week, may request. With several groups the most
 * generous wins; what no group sets is left as it is.
 */
class GroupRules
{
    public function apply(User $user): void
    {
        $groups = $user->groups()->get()->filter(fn (MemberGroup $g) => $g->hasRules());
        if ($groups->isEmpty()) {
            return;
        }
        $changes = [];
        if ($groups->contains('no_end', true) && $user->access_expires_at !== null) {
            $changes['access_expires_at'] = null;
        }
        foreach (['max_streams', 'request_limit'] as $limit) {
            $values = $groups->pluck($limit)->reject(fn ($v) => $v === null);
            if ($values->isNotEmpty() && $values->max() !== $user->{$limit}) {
                $changes[$limit] = $values->max();
            }
        }
        $seerr = $groups->pluck('seerr_access')->reject(fn ($v) => $v === null);
        if ($seerr->isNotEmpty() && $seerr->contains(true) !== $user->seerr_access) {
            $changes['seerr_access'] = $seerr->contains(true);
        }
        if ($changes !== []) {
            $user->forceFill($changes)->save();
            Audit::log('member.group_rules', $user, ['changed' => array_keys($changes)]);
            if (array_key_exists('access_expires_at', $changes) && $user->disabled_at === null) {
                app(AccessManager::class)->enableAll($user);
            }
        }
    }

    public function applyToGroup(MemberGroup $group): void
    {
        foreach ($group->users as $user) {
            $this->apply($user);
        }
    }
}
