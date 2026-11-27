<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Models\ServerAccount;
use App\Models\User;
use App\Notifications\AccessEnded;
use App\Notifications\AccessEndingSoon;
use App\Services\AccessManager;
use App\Services\Audit;
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

    public function handle(AccessManager $access): int
    {
        $warnDays = (int) config('clubarr.expiry_warning_days');
        User::members()->whereNull('disabled_at')->whereNull('expiry_warned_at')->whereNotNull('access_expires_at')
            ->whereBetween('access_expires_at', [now(), now()->addDays($warnDays)])
            ->each(function (User $user) {
                $this->send($user, new AccessEndingSoon);
                $user->forceFill(['expiry_warned_at' => now()])->saveQuietly();
            });

        $ended = 0;
        User::members()->whereNotNull('access_expires_at')->where('access_expires_at', '<=', now())
            ->whereHas('serverAccounts', fn ($q) => $q->where('status', AccountStatus::Active))
            ->each(function (User $user) use ($access, &$ended) {
                $failed = $access->disableAll($user, 'access ended');
                Audit::log('member.access_ended', $user, $failed !== [] ? ['failed' => $failed] : []);
                if ($failed === []) {
                    $this->send($user, new AccessEnded);
                }
                $ended++;
            });

        ServerAccount::with('mediaServer')->where('status', AccountStatus::Pending)->where('created_at', '>=', now()->subDays(30))
            ->each(fn (ServerAccount $account) => $access->refreshPending($account));

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
