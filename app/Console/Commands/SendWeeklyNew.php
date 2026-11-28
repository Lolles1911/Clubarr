<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\WeeklyNew;
use App\Services\NewOnServers;
use App\Services\SiteSettings;
use Illuminate\Console\Command;
use Throwable;

/** Mondays: what is new on their servers, to members who turned the mail on (nothing new: no mail). */
class SendWeeklyNew extends Command
{
    protected $signature = 'clubarr:weekly-new';

    protected $description = 'Mail members who want it what is new on their servers';

    public function handle(NewOnServers $new): int
    {
        if (app(SiteSettings::class)->get('mail_enabled') !== '1') {
            return self::SUCCESS;
        }
        $sent = 0;
        User::members()->where('digest', true)->whereNull('disabled_at')->each(function (User $user) use ($new, &$sent) {
            if (! $user->hasActiveAccess()) {
                return;
            }
            $items = $new->addedSince($user, $user->digest_sent_at ?? now()->subWeek());
            if ($items === []) {
                return;
            }
            try {
                $user->notify(new WeeklyNew($items));
                $user->forceFill(['digest_sent_at' => now()])->save();
                $sent++;
            } catch (Throwable $e) {
                report($e);
            }
        });
        $this->info('Sent '.$sent.'.');

        return self::SUCCESS;
    }
}
