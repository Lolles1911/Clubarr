<?php

namespace App\Support;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Services\MemberInvites;

/** A member's access at a glance, for their front page and My page. */
final class AccountSummary
{
    /** @return array{ends: ?string, groups: list<string>, servers: int, invites: ?int, requests: ?string} */
    public static function of(User $member): array
    {
        return [
            'ends' => $member->access_expires_at?->translatedFormat(__('j F Y')),
            'groups' => $member->groups()->pluck('name')->all(),
            'servers' => $member->serverAccounts()->where('status', AccountStatus::Active)->count(),
            'invites' => MemberInvites::allowed($member) ? MemberInvites::remaining($member) : null,
            'requests' => ! $member->seerr_access ? null : ($member->request_limit === null ? __('No limit') : __(':count a week', ['count' => $member->request_limit])),
        ];
    }
}
