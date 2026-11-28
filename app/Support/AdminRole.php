<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Admins are full admins, or moderators: members, invitations, requests,
 * applications, problems, messages and statistics, but not settings,
 * servers, groups, API keys or the log, and never other admins.
 */
final class AdminRole
{
    public const MODERATOR = 'moderator';

    /** @return array<string, string> */
    public static function options(): array
    {
        return ['admin' => __('Admin (everything)'), self::MODERATOR => __('Moderator (members and requests)')];
    }

    public static function full(?User $user = null): bool
    {
        $user ??= Auth::user() instanceof User ? Auth::user() : null;

        return $user !== null && $user->is_admin && $user->admin_role === null;
    }
}
