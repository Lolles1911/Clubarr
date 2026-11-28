<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\Audit;
use App\Services\MemberAdmin;
use App\Services\MemberRequests;
use App\Services\Retention;
use App\Services\ServerSync;
use App\Services\SiteSettings;
use App\Services\Watching;
use Illuminate\Console\Command;

/**
 * Daily, when turned on under Settings → Automation:
 * - inactive members (nothing watched for the set days) get a mail a week
 *   before, then are paused, which frees their seat;
 * - users on the servers are deleted the set days after access ended (the
 *   Clubarr account stays, so a new invitation can bring them back);
 * - old data is removed as set (see Retention).
 */
class Housekeeping extends Command
{
    public const WARN_DAYS = 7;

    protected $signature = 'clubarr:housekeeping';

    protected $description = 'Pause inactive members and clean up after ended access';

    public function handle(Watching $watching, MemberAdmin $admin, AccessManager $access): int
    {
        $settings = app(SiteSettings::class);
        $inactive = (int) $settings->get('inactive_days');
        if ($inactive > 0) {
            $this->seenInTracearr($watching);
            $paused = $warned = 0;
            User::members()->whereNull('disabled_at')->with('serverAccounts')->each(function (User $user) use ($inactive, $admin, &$paused, &$warned) {
                if (! $user->hasActiveAccess()) {
                    return;
                }
                $since = $user->last_seen_at ?? $user->created_at;
                if ($since->isAfter(now()->subDays(max(1, $inactive - self::WARN_DAYS)))) {
                    return;
                }
                if ($user->inactive_warned_at === null) {
                    $user->forceFill(['inactive_warned_at' => now()])->saveQuietly();
                    MemberRequests::tell($user, __('We miss you'), __('You have not watched anything for a while. If it stays like that, your access is paused in :days days, so someone else can have the place. Just watch something to keep it.', ['days' => self::WARN_DAYS]));
                    $warned++;
                } elseif ($since->isBefore(now()->subDays($inactive)) && $user->inactive_warned_at->isBefore(now()->subDays(self::WARN_DAYS - 1))) {
                    $admin->disable($user);
                    Audit::log('member.paused_inactive', $user, ['days' => $inactive], null);
                    MemberRequests::tell($user, __('Your access is paused'), __('You had not watched anything for :days days, so your access is paused. Ask us if you want it back.', ['days' => $inactive]));
                    $paused++;
                }
            });
            $this->info("Inactive: warned {$warned}, paused {$paused}.");
        }

        $cleanup = (int) $settings->get('cleanup_days');
        if ($cleanup > 0) {
            $removed = 0;
            User::members()->whereNotNull('access_expires_at')->where('access_expires_at', '<', now()->subDays($cleanup))
                ->whereHas('serverAccounts')->each(function (User $user) use ($access, &$removed) {
                    if ($access->removeAll($user) === []) {
                        Audit::log('member.cleaned_up', $user, [], null);
                        $removed++;
                    }
                });
            $this->info("Cleaned up {$removed}.");
        }

        $cleaned = app(Retention::class)->run();
        if ($cleaned !== []) {
            $this->info('Old data removed: '.json_encode($cleaned).'.');
        }

        return self::SUCCESS;
    }

    /** Last plays from Tracearr (it knows Plex too). */
    private function seenInTracearr(Watching $watching): void
    {
        if (! $watching->enabled()) {
            return;
        }
        $last = [];
        foreach ($watching->plays() as $play) {
            $key = Watching::keyOf($play);
            $at = strtotime((string) ($play['started_at'] ?? '')) ?: 0;
            $last[$key] = max($last[$key] ?? 0, $at);
        }
        if ($last === []) {
            return;
        }
        User::members()->whereNull('disabled_at')->each(function (User $user) use ($last) {
            $at = max(array_map(fn ($k) => $last[$k] ?? 0, array_keys(Watching::keysOf($user))) ?: [0]);
            if ($at > 0) {
                ServerSync::seen($user, $at);
            }
        });
    }
}
