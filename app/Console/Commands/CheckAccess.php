<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Models\ServerAccount;
use App\Models\User;
use App\Notifications\AccessEnded;
use App\Notifications\AccessEndingSoon;
use App\Services\AccessManager;
use App\Services\Audit;
use App\Services\DiscordSync;
use App\Services\SelfService;
use App\Services\SiteSettings;
use Illuminate\Console\Command;
use Throwable;

/**
 * Hourly: warns members whose access ends soon, pauses the users of those
 * whose access has ended, and picks up Silo links and Plex shares that were
 * taken.
 */
class CheckAccess extends Command
{
    protected $signature = 'clubarr:check-access';

    protected $description = 'Warn about and end expired access; pick up taken Silo links and Plex shares';

    public function handle(AccessManager $access, DiscordSync $discord): int
    {
        $warnDays = (int) config('clubarr.expiry_warning_days');
        User::members()->whereNull('disabled_at')->whereNull('expiry_warned_at')->whereNotNull('access_expires_at')
            ->whereBetween('access_expires_at', [now(), now()->addDays($warnDays)])
            ->each(function (User $user) use ($discord) {
                $this->send($user, new AccessEndingSoon);
                $discord->tell($user, __('Your access to :site ends on :date. Want to keep watching? Ask the person who invited you.',
                    ['site' => app(SiteSettings::class)->get('site_name'), 'date' => $user->access_expires_at?->translatedFormat(__('j F Y'))]));
                $user->forceFill(['expiry_warned_at' => now()])->saveQuietly();
            });

        // Members who paused themselves come back when their pause is over.
        User::members()->whereNotNull('self_paused_at')->where('self_pause_until', '<=', now())
            ->each(fn (User $user) => app(SelfService::class)->resume($user));

        $ended = 0;
        User::members()->whereNotNull('access_expires_at')->where('access_expires_at', '<=', now())
            ->whereHas('serverAccounts', fn ($q) => $q->where('status', AccountStatus::Active))
            ->each(function (User $user) use ($access, $discord, &$ended) {
                $failed = $access->disableAll($user, 'access ended');
                Audit::log('member.access_ended', $user, $failed !== [] ? ['failed' => $failed] : []);
                if ($failed === []) {
                    $this->send($user, new AccessEnded);
                    $discord->tell($user, __('Your access to :site has ended', ['site' => app(SiteSettings::class)->get('site_name')]).'.');
                }
                $ended++;
            });

        ServerAccount::with('mediaServer')->where('status', AccountStatus::Pending)->where('created_at', '>=', now()->subDays(30))
            ->each(fn (ServerAccount $account) => $access->refreshPending($account));

        // Discord roles follow access (also after changes made elsewhere).
        User::members()->whereNotNull('discord_id')->each(fn (User $user) => $discord->sync($user));

        $this->info('Access checked; '.$ended.' ended.');

        return self::SUCCESS;
    }

    private function send(User $user, object $notification): void
    {
        try {
            $user->notify($notification);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
