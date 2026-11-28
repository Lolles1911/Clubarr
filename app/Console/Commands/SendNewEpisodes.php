<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MemberHistory;
use App\Services\MemberRequests;
use App\Services\NewOnServers;
use App\Services\SiteSettings;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily: "New episodes of your shows": shows a member watched in the last
 * 90 days that got something new on their servers since the last message.
 * Mail and Discord; members can turn it off on My page.
 */
class SendNewEpisodes extends Command
{
    protected $signature = 'clubarr:new-episodes';

    protected $description = 'Tell members about new episodes of the shows they watch';

    public function handle(NewOnServers $new, MemberHistory $history): int
    {
        if (app(SiteSettings::class)->get('episode_alerts') === '0') {
            return self::SUCCESS;
        }
        $told = 0;
        User::members()->where('episode_alerts', true)->whereNull('disabled_at')->each(function (User $user) use ($new, $history, &$told) {
            try {
                if (! $user->hasActiveAccess()) {
                    return;
                }
                $since = now()->subDays(90)->getTimestamp();
                $shows = collect($history->yearPlays($user))
                    ->filter(fn ($p) => ($p['media_type'] ?? '') === 'episode' && (strtotime((string) ($p['started_at'] ?? '')) ?: 0) >= $since)
                    ->map(fn ($p) => mb_strtolower(trim((string) ($p['show_title'] ?? ''))))->filter()->unique()->all();
                if ($shows === []) {
                    return;
                }
                $fresh = collect($new->addedSince($user, $user->episodes_told_at ?? now()->subDay()))
                    ->filter(fn ($i) => in_array(mb_strtolower($i['title']), $shows, true))->pluck('title')->unique()->values();
                $user->forceFill(['episodes_told_at' => now()])->saveQuietly();
                if ($fresh->isEmpty()) {
                    return;
                }
                MemberRequests::tell($user, __('New episodes of your shows'), __('New on the server: :shows. Enjoy!', ['shows' => $fresh->implode(', ')]));
                $told++;
            } catch (Throwable $e) {
                report($e);
            }
        });
        $this->info('Told '.$told.'.');

        return self::SUCCESS;
    }
}
