<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Integrations\Tautulli;
use App\Integrations\Tracearr;
use App\Integrations\WatchSource;
use App\Models\ServerAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What members watch, from Tracearr: streams now (and stopping them),
 * devices, numbers, posters, and the limit on streams at the same time.
 * A member's plays are found by server type and user name, as Tracearr
 * names them. Everything cached is plain arrays.
 */
class Watching
{
    public function __construct(private ?WatchSource $tracearr = null)
    {
        $this->tracearr ??= Tracearr::fromSettings() ?? Tautulli::fromSettings();
    }

    public function enabled(): bool
    {
        return $this->tracearr !== null;
    }

    /** "type|username" of a stream or play, as Tracearr gives it. */
    public static function keyOf(array $row): string
    {
        return strtolower((string) ($row['server_type'] ?? '')).'|'.strtolower((string) ($row['username'] ?? $row['user']['username'] ?? ''));
    }

    /** @return array<string, true> the member's users, as "type|username" */
    public static function keysOf(User $user): array
    {
        $keys = [];
        foreach ($user->serverAccounts()->with('mediaServer')->whereNotNull('remote_username')->get() as $account) {
            $keys[$account->mediaServer->type->value.'|'.strtolower((string) $account->remote_username)] = true;
        }
        // Their family's extra users count as theirs (streams at once, numbers).
        foreach ($user->extraUsers()->with('mediaServer')->get() as $extra) {
            $keys[$extra->mediaServer->type->value.'|'.strtolower($extra->remote_username)] = true;
        }

        return $keys;
    }

    /** @return list<array<string, mixed>> the member's streams now */
    public function streamsOf(User $user): array
    {
        if (! $this->enabled()) {
            return [];
        }
        $keys = self::keysOf($user);

        return array_values(array_filter($this->tracearr->streams(), fn (array $s) => isset($keys[self::keyOf($s)])));
    }

    /** Stops one of the member's own streams; false when it is not theirs. */
    public function stop(User $user, string $sessionId): bool
    {
        if (! in_array($sessionId, array_map(fn ($s) => (string) ($s['id'] ?? ''), $this->streamsOf($user)), true)) {
            return false;
        }
        $this->tracearr->terminate($sessionId, __('Stopped from your account.'));
        Audit::log('stream.stopped', $user, ['by' => 'member']);

        return true;
    }

    /** @return list<array<string, mixed>> everyone's plays of the last 30 days (kept 5 minutes) */
    public function plays(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return Cache::remember('clubarr:tracearr:plays', 300, function () {
            try {
                return $this->tracearr->history(now()->subDays(30)->getTimestamp(), 6000);
            } catch (Throwable $e) {
                Log::info('Clubarr: Tracearr plays not read: '.$e->getMessage());

                return [];
            }
        });
    }

    /** @return list<array{who: string, title: string, player: string}> everyone's streams now (kept 30 seconds), for the dashboard */
    public function everyoneNow(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return Cache::remember('clubarr:tracearr:now', 30, function () {
            try {
                return array_map(fn (array $s) => [
                    'who' => (string) ($s['username'] ?? $s['user']['username'] ?? '?'),
                    'title' => trim(($s['media_type'] ?? '') === 'episode' ? ($s['show_title'] ?? '').' · '.($s['media_title'] ?? '') : (string) ($s['media_title'] ?? '')),
                    'player' => (string) ($s['player'] ?? $s['device'] ?? $s['platform'] ?? ''),
                ], $this->tracearr->streams());
            } catch (Throwable) {
                return [];
            }
        });
    }

    /** @return list<array{title: string, plays: int, people: int}> most watched in 30 days */
    public function topTitles(int $limit = 10): array
    {
        $top = [];
        foreach ($this->plays() as $play) {
            $title = ($play['media_type'] ?? '') === 'episode' ? (string) ($play['show_title'] ?? '') : (string) ($play['media_title'] ?? '');
            if ($title === '' || ($play['media_type'] ?? '') === 'track') {
                continue;
            }
            $top[$title] ??= ['title' => $title, 'plays' => 0, 'people' => []];
            $top[$title]['plays']++;
            $top[$title]['people'][self::keyOf($play)] = true;
        }
        uasort($top, fn ($a, $b) => [count($b['people']), $b['plays']] <=> [count($a['people']), $a['plays']]);

        return array_map(fn ($t) => ['title' => $t['title'], 'plays' => $t['plays'], 'people' => count($t['people'])], array_slice(array_values($top), 0, $limit));
    }

    /** @return list<array<string, mixed>> everyone's plays of the last 365 days (kept 6 hours), for the year and the stats */
    public function yearPlays(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return Cache::remember('clubarr:watch:year', 6 * 3600, function () {
            try {
                return $this->tracearr->history(now()->subDays(365)->getTimestamp(), 40000);
            } catch (Throwable $e) {
                Log::info('Clubarr: year of plays not read: '.$e->getMessage());

                return [];
            }
        });
    }

    /**
     * Numbers for a set of plays (everyone's, or one member's).
     *
     * @param  list<array<string, mixed>>  $plays
     * @return array{hours: float, plays: int, days: array<string, float>, hours_of_day: array<int, float>, weekdays: array<int, float>, platforms: array<string, int>, titles: list<array{title: string, hours: float, plays: int}>, people: array<string, float>, first: ?int, last: ?int}
     */
    public static function summarize(array $plays, int $days = 30): array
    {
        $since = now()->subDays($days - 1)->startOfDay();
        $byDay = [];
        for ($d = $since->copy(); $d->lte(now()); $d->addDay()) {
            $byDay[$d->toDateString()] = 0.0;
        }
        $hoursOfDay = array_fill(0, 24, 0.0);
        $weekdays = array_fill(1, 7, 0.0);
        $platforms = $titles = $people = [];
        $total = 0.0;
        $first = $last = null;
        foreach ($plays as $play) {
            $at = strtotime((string) ($play['started_at'] ?? '')) ?: 0;
            $hours = max(0, (int) ($play['duration_ms'] ?? $play['watched_ms'] ?? 0)) / 3600000;
            $total += $hours;
            $first = $first === null ? $at : min($first, $at);
            $last = $last === null ? $at : max($last, $at);
            $date = date('Y-m-d', $at);
            if (isset($byDay[$date])) {
                $byDay[$date] += $hours;
            }
            $hoursOfDay[(int) date('G', $at)] += $hours;
            $weekdays[(int) date('N', $at)] += $hours;
            $platform = trim((string) ($play['platform'] ?? $play['device'] ?? '')) ?: '?';
            $platforms[$platform] = ($platforms[$platform] ?? 0) + 1;
            $title = ($play['media_type'] ?? '') === 'episode' ? (string) ($play['show_title'] ?? '') : (string) ($play['media_title'] ?? '');
            if ($title !== '' && ($play['media_type'] ?? '') !== 'track') {
                $titles[$title] ??= ['title' => $title, 'hours' => 0.0, 'plays' => 0];
                $titles[$title]['hours'] += $hours;
                $titles[$title]['plays']++;
            }
            $people[self::keyOf($play)] = ($people[self::keyOf($play)] ?? 0) + $hours;
        }
        usort($titles, fn ($a, $b) => $b['hours'] <=> $a['hours']);
        arsort($platforms);
        arsort($people);

        return ['hours' => round($total, 1), 'plays' => count($plays), 'days' => array_map(fn ($h) => round($h, 1), $byDay),
            'hours_of_day' => array_map(fn ($h) => round($h, 1), $hoursOfDay), 'weekdays' => array_map(fn ($h) => round($h, 1), $weekdays),
            'platforms' => array_slice($platforms, 0, 8, true), 'titles' => array_map(fn ($t) => ['title' => $t['title'], 'hours' => round($t['hours'], 1), 'plays' => $t['plays']], array_slice($titles, 0, 10)),
            'people' => array_map(fn ($h) => round($h, 1), $people), 'first' => $first, 'last' => $last];
    }

    /** @return list<array<string, mixed>> one member's plays of the last 365 days */
    public function yearPlaysOf(User $user): array
    {
        $keys = self::keysOf($user);

        return array_values(array_filter($this->yearPlays(), fn (array $p) => isset($keys[self::keyOf($p)])));
    }

    /** @return list<array<string, mixed>> the member's plays of the last 30 days, newest first */
    public function playsOf(User $user): array
    {
        $keys = self::keysOf($user);

        return array_values(array_filter($this->plays(), fn (array $p) => isset($keys[self::keyOf($p)])));
    }

    /** @return list<array{device: string, player: string, server: string, last: int, plays: int}> newest first */
    public function devicesOf(User $user): array
    {
        $devices = [];
        foreach ($this->playsOf($user) as $play) {
            $device = trim((string) ($play['device'] ?? '')) ?: trim((string) ($play['platform'] ?? '')) ?: '?';
            $player = trim((string) ($play['player'] ?? ''));
            $key = strtolower($device.'|'.$player);
            $at = strtotime((string) ($play['started_at'] ?? '')) ?: 0;
            $devices[$key] ??= ['device' => $device, 'player' => $player, 'server' => ucfirst((string) ($play['server_type'] ?? '')), 'last' => 0, 'plays' => 0];
            $devices[$key]['plays']++;
            $devices[$key]['last'] = max($devices[$key]['last'], $at);
        }
        usort($devices, fn ($a, $b) => $b['last'] <=> $a['last']);

        return $devices;
    }

    /** @return array{hours7: float, hours30: float, plays30: int} */
    public function statsOf(User $user): array
    {
        $week = now()->subDays(7)->getTimestamp();
        $hours7 = $hours30 = 0.0;
        $plays = $this->playsOf($user);
        foreach ($plays as $play) {
            $hours = max(0, (int) ($play['duration_ms'] ?? $play['watched_ms'] ?? 0)) / 3600000;
            $hours30 += $hours;
            if ((strtotime((string) ($play['started_at'] ?? '')) ?: 0) >= $week) {
                $hours7 += $hours;
            }
        }

        return ['hours7' => round($hours7, 1), 'hours30' => round($hours30, 1), 'plays30' => count($plays)];
    }

    /**
     * Stops streams over each member's limit, newest first. Returns how many
     * were stopped. Runs every minute.
     */
    public function enforceLimits(): int
    {
        if (! $this->enabled()) {
            return 0;
        }
        $limited = [];
        foreach (ServerAccount::with(['mediaServer', 'user'])->whereNotNull('remote_username')->where('status', AccountStatus::Active)
            ->whereHas('user', fn ($q) => $q->whereNotNull('max_streams'))->get() as $account) {
            $limited[$account->mediaServer->type->value.'|'.strtolower((string) $account->remote_username)] = $account->user;
        }
        if ($limited === []) {
            return 0;
        }
        $byUser = [];
        foreach ($this->tracearr->streams() as $stream) {
            $user = $limited[self::keyOf($stream)] ?? null;
            if ($user !== null) {
                $byUser[$user->id]['user'] = $user;
                $byUser[$user->id]['streams'][] = $stream;
            }
        }
        $stopped = 0;
        foreach ($byUser as ['user' => $user, 'streams' => $streams]) {
            $extra = count($streams) - (int) $user->max_streams;
            if ($extra <= 0) {
                continue;
            }
            usort($streams, fn ($a, $b) => strcmp((string) ($b['started_at'] ?? ''), (string) ($a['started_at'] ?? '')));
            foreach (array_slice($streams, 0, $extra) as $stream) {
                try {
                    $this->tracearr->terminate((string) $stream['id'], __('Your account allows :count streams at the same time.', ['count' => (int) $user->max_streams]));
                    $stopped++;
                    Audit::log('stream.limit', $user, ['limit' => $user->max_streams, 'title' => (string) ($stream['media_title'] ?? '')]);
                } catch (Throwable $e) {
                    Log::warning('Clubarr: stream over the limit not stopped: '.$e->getMessage());
                }
            }
        }

        return $stopped;
    }

    /**
     * Poster rows for the member: continue watching, popular this week, new.
     *
     * @return array{continue: list<array>, popular: list<array>, new: list<array>}
     */
    public function posterRows(User $user): array
    {
        if (! $this->enabled()) {
            return ['continue' => [], 'popular' => [], 'new' => []];
        }
        $continue = [];
        foreach ($this->playsOf($user) as $play) {
            $title = ($play['media_type'] ?? '') === 'episode' ? (string) ($play['show_title'] ?? '') : (string) ($play['media_title'] ?? '');
            $percent = (float) ($play['percent_complete'] ?? 0);
            if ($title === '' || isset($continue[$title])) {
                continue;
            }
            $continue[$title] = $percent >= 3 && $percent <= 90 && empty($play['watched']) ? ['title' => $title, 'path' => (string) ($play['poster_url'] ?? ''), 'percent' => (int) $percent] : null;
        }
        $week = now()->subDays(7)->getTimestamp();
        $popular = [];
        foreach ($this->plays() as $play) {
            if ((strtotime((string) ($play['started_at'] ?? '')) ?: 0) < $week) {
                continue;
            }
            $title = ($play['media_type'] ?? '') === 'episode' ? (string) ($play['show_title'] ?? '') : (string) ($play['media_title'] ?? '');
            if ($title === '' || ($play['media_type'] ?? '') === 'track') {
                continue;
            }
            $popular[$title] ??= ['title' => $title, 'path' => (string) ($play['poster_url'] ?? ''), 'people' => []];
            $popular[$title]['people'][self::keyOf($play)] = true;
        }
        uasort($popular, fn ($a, $b) => count($b['people']) <=> count($a['people']));
        $new = Cache::remember('clubarr:tracearr:new', 3600, function () {
            try {
                return array_map(fn (array $row) => ['title' => (string) ($row['title'] ?? ''), 'path' => $this->tracearr->addedPoster($row) ?? ''],
                    $this->tracearr->recentlyAdded(now()->subDays(45)->getTimestamp(), 40));
            } catch (Throwable) {
                return [];
            }
        });

        return [
            'continue' => $this->allow(array_slice(array_values(array_filter($continue)), 0, 12)),
            'popular' => $this->allow(array_map(fn ($p) => ['title' => $p['title'], 'path' => $p['path']], array_slice(array_values($popular), 0, 20))),
            'new' => $this->allow(array_slice($new, 0, 20)),
        ];
    }

    /** Keeps only items with a Tracearr poster, and lists those posters as allowed for /posters/{key}. */
    private function allow(array $items): array
    {
        $allowed = Cache::get('clubarr:posters', []);
        $out = [];
        foreach ($items as $item) {
            if (! str_starts_with((string) $item['path'], '/api/v1/images/proxy?')) {
                continue;
            }
            $item['key'] = substr(hash('sha256', $item['path']), 0, 20);
            $allowed[$item['key']] = $item['path'];
            unset($item['path']);
            $out[] = $item;
        }
        Cache::put('clubarr:posters', array_slice($allowed, -1000, null, true), 3 * 86400);

        return $out;
    }

    /** A listed poster's image (kept a day), or null. */
    public function poster(string $key): ?array
    {
        $path = Cache::get('clubarr:posters', [])[$key] ?? null;
        if (! is_string($path) || ! $this->enabled()) {
            return null;
        }
        $image = Cache::remember('clubarr:poster:'.$key, 86400, function () use ($path) {
            $found = $this->tracearr->poster($path);

            return $found ? ['type' => $found['type'], 'body' => base64_encode($found['body'])] : false;
        });

        return is_array($image) ? ['type' => $image['type'], 'body' => base64_decode($image['body'])] : null;
    }
}
