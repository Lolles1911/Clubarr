<?php

namespace App\Jobs;

use App\Models\Announcement;
use App\Notifications\MemberMessage;
use App\Services\DiscordSync;
use App\Services\SiteMail;
use App\Services\SiteSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Sends a message to its members by mail and/or Discord, on the queue (it can be many). */
class SendAnnouncement implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $announcementId) {}

    public function handle(DiscordSync $discord): void
    {
        $announcement = Announcement::find($this->announcementId);
        if ($announcement === null || $announcement->sent_at !== null) {
            return; // gone, or already sent
        }
        $announcement->forceFill(['sent_at' => now()])->save();
        $mail = $announcement->by_mail && app(SiteSettings::class)->get('mail_enabled') === '1';
        if ($mail) {
            app(SiteMail::class)->apply();
        }
        $mailed = $discorded = 0;
        $announcement->recipients()->chunkById(100, function ($users) use ($announcement, $mail, $discord, &$mailed, &$discorded) {
            foreach ($users as $user) {
                if ($mail) {
                    try {
                        $user->notify(new MemberMessage($announcement));
                        $mailed++;
                    } catch (Throwable $e) {
                        Log::info('Clubarr: message '.$announcement->id.' not mailed to user '.$user->id.': '.class_basename($e));
                    }
                }
                if ($announcement->by_discord && $user->discord_id !== null && $discord->enabled()) {
                    $discord->tell($user, '**'.$announcement->title."**\n".$announcement->body);
                    $discorded++;
                }
            }
        });
        $announcement->forceFill(['mailed' => $mailed, 'discorded' => $discorded])->save();
    }
}
