<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Integrations\Tracearr;
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
    public function __construct(private ?Tracearr $tracearr = null)
    {
        $this->tracearr ??= Tracearr::fromSettings();
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
                return array_map(fn (array $row) => ['title' => (string) ($row['title'] ?? ''), 'path' => self::addedPoster($row) ?? ''],
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

    /** Tracearr's poster path for a title from "recently added". */
    public static function addedPoster(array $row): ?string
    {
        $id = (string) ($row['rating_key'] ?? '');
        $server = (string) ($row['server_id'] ?? '');
        if ($id === '' || $server === '') {
            return null;
        }
        $path = match (strtolower((string) ($row['server_type'] ?? ''))) {
            'plex' => '/library/metadata/'.$id.'/thumb',
            'silo' => '/content/'.$id.'/poster',
            default => '/Items/'.$id.'/Images/Primary',
        };

        return '/api/v1/images/proxy?'.http_build_query(['server' => $server, 'url' => $path, 'width' => 300, 'height' => 450, 'fallback' => 'poster']);
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
