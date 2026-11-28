<?php

namespace App\Services;

use App\MediaServers\ServerHttp;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "Coming soon": episodes (Sonarr) and films (Radarr) expected in the next
 * two weeks, for the members' page. Kept an hour as plain arrays. Posters
 * only when they are TMDB's, through Clubarr's own TMDB proxy.
 */
class ComingSoon
{
    public const DAYS = 14;

    public static function enabled(): bool
    {
        $s = app(SiteSettings::class);

        return (filled($s->get('sonarr_url')) && filled($s->get('sonarr_api_key'))) || (filled($s->get('radarr_url')) && filled($s->get('radarr_api_key')));
    }

    /** @return list<array{title: string, detail: string, date: string, tmdb: ?string}> soonest first (cached) */
    public function items(): array
    {
        if (! self::enabled()) {
            return [];
        }

        return Cache::remember('clubarr:coming-soon', 3600, function () {
            try {
                return $this->fetch();
            } catch (Throwable) {
                return [];
            }
        });
    }

    /** @return list<array{title: string, detail: string, date: string, tmdb: ?string}> */
    public function fetch(): array
    {
        $s = app(SiteSettings::class);
        $window = ['start' => now()->toDateString(), 'end' => now()->addDays(self::DAYS)->toDateString()];
        $items = [];
        if (filled($s->get('sonarr_url')) && filled($s->get('sonarr_api_key'))) {
            $sonarr = new ServerHttp((string) $s->get('sonarr_url'), ['X-Api-Key' => (string) $s->get('sonarr_api_key')], 'Sonarr');
            $seen = [];
            foreach (array_filter($sonarr->json('GET', '/api/v3/calendar', $window + ['includeSeries' => 'true']), 'is_array') as $e) {
                $title = (string) ($e['series']['title'] ?? '');
                $date = (string) ($e['airDateUtc'] ?? $e['airDate'] ?? '');
                if ($title === '' || $date === '' || ! empty($e['hasFile']) || isset($seen[$title])) {
                    continue; // one line per show: its next episode
                }
                $seen[$title] = true;
                $items[] = ['title' => $title, 'detail' => sprintf('S%02dE%02d', (int) ($e['seasonNumber'] ?? 0), (int) ($e['episodeNumber'] ?? 0)),
                    'date' => substr($date, 0, 10), 'tmdb' => self::tmdbFile($e['series']['images'] ?? [])];
            }
        }
        if (filled($s->get('radarr_url')) && filled($s->get('radarr_api_key'))) {
            $radarr = new ServerHttp((string) $s->get('radarr_url'), ['X-Api-Key' => (string) $s->get('radarr_api_key')], 'Radarr');
            foreach (array_filter($radarr->json('GET', '/api/v3/calendar', $window), 'is_array') as $m) {
                $date = (string) ($m['digitalRelease'] ?? $m['physicalRelease'] ?? $m['inCinemas'] ?? '');
                if (empty($m['title']) || $date === '' || ! empty($m['hasFile'])) {
                    continue;
                }
                $items[] = ['title' => (string) $m['title'], 'detail' => (string) ($m['year'] ?? ''), 'date' => substr($date, 0, 10), 'tmdb' => self::tmdbFile($m['images'] ?? [])];
            }
        }
        usort($items, fn ($a, $b) => $a['date'] <=> $b['date']);

        return array_slice($items, 0, 20);
    }

    /** The poster's file name when it is a TMDB image (served by /tmdb/{file}). */
    private static function tmdbFile(mixed $images): ?string
    {
        foreach (is_array($images) ? $images : [] as $image) {
            $url = (string) ($image['remoteUrl'] ?? '');
            if (($image['coverType'] ?? '') === 'poster' && preg_match('~^https://image\.tmdb\.org/t/p/[a-z0-9]+/([A-Za-z0-9._-]{5,64}\.(?:jpg|png))$~', $url, $m)) {
                return $m[1];
            }
        }

        return null;
    }
}
