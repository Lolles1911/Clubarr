<?php

namespace App\Integrations;

use App\MediaServers\MediaServerException;
use App\MediaServers\ServerHttp;
use App\Services\SiteSettings;

/**
 * Tautulli (Plex): what plays now, stopping a stream, plays and what was
 * added, translated to Tracearr's field names (see WatchSource). Tautulli
 * only takes its key in the address; ServerHttp never puts addresses in
 * errors, so the key stays out of logs and messages.
 */
class Tautulli implements WatchSource
{
    private ServerHttp $http;

    public function __construct(string $url, private string $key)
    {
        $this->http = new ServerHttp($url, [], 'Tautulli');
    }

    public static function fromSettings(): ?self
    {
        $settings = app(SiteSettings::class);
        $url = (string) $settings->get('tautulli_url');
        $key = (string) $settings->get('tautulli_api_key');

        return $url !== '' && $key !== '' ? new self($url, $key) : null;
    }

    /** @return array<string, mixed> the "data" of an API command */
    private function call(string $cmd, array $params = []): array
    {
        $answer = $this->http->json('GET', '/api/v2', ['apikey' => $this->key, 'cmd' => $cmd] + $params)['response'] ?? [];
        if (($answer['result'] ?? '') !== 'success') {
            throw new MediaServerException(__('Tautulli answered: :message', ['message' => mb_substr((string) ($answer['message'] ?? '?'), 0, 200)]));
        }

        return is_array($answer['data'] ?? null) ? $answer['data'] : [];
    }

    public function version(): string
    {
        return (string) ($this->call('get_tautulli_info')['tautulli_version'] ?? '?');
    }

    public function streams(): array
    {
        return array_values(array_map(fn (array $s) => [
            'id' => (string) ($s['session_id'] ?? ''),
            'server_type' => 'plex',
            'username' => (string) ($s['user'] ?? ''),
            'media_type' => (string) ($s['media_type'] ?? ''),
            'show_title' => (string) ($s['grandparent_title'] ?? ''),
            'media_title' => (string) ($s['title'] ?? ''),
            'player' => (string) ($s['player'] ?? ''),
            'platform' => (string) ($s['platform'] ?? ''),
            'device' => (string) ($s['platform'] ?? ''),
            'started_at' => isset($s['start_time']) ? gmdate('c', (int) $s['start_time']) : null,
        ], array_filter($this->call('get_activity')['sessions'] ?? [], 'is_array')));
    }

    public function terminate(string $sessionId, string $reason): void
    {
        $this->call('terminate_session', ['session_id' => $sessionId, 'message' => mb_substr($reason, 0, 500)]);
    }

    public function history(int $since, int $max = 3000): array
    {
        $rows = [];
        for ($start = 0; $start < $max; $start += 500) {
            $page = $this->call('get_history', ['start' => $start, 'length' => 500, 'order_column' => 'date', 'order_dir' => 'desc', 'after' => gmdate('Y-m-d', $since)])['data'] ?? [];
            foreach (array_filter($page, 'is_array') as $p) {
                if ((int) ($p['started'] ?? 0) < $since) {
                    return $rows;
                }
                $rows[] = [
                    'server_type' => 'plex',
                    'username' => (string) ($p['user'] ?? ''),
                    'media_type' => (string) ($p['media_type'] ?? ''),
                    'show_title' => (string) ($p['grandparent_title'] ?? ''),
                    'media_title' => (string) ($p['title'] ?? ''),
                    'started_at' => gmdate('c', (int) ($p['started'] ?? 0)),
                    'duration_ms' => (int) ($p['play_duration'] ?? $p['duration'] ?? 0) * 1000,
                    'percent_complete' => (int) ($p['percent_complete'] ?? 0),
                    'watched' => (int) ($p['watched_status'] ?? 0) === 1,
                    'player' => (string) ($p['player'] ?? ''),
                    'platform' => (string) ($p['platform'] ?? ''),
                    'device' => (string) ($p['platform'] ?? ''),
                    'poster_url' => self::posterPath((string) ($p['media_type'] ?? '') === 'episode' ? ($p['grandparent_thumb'] ?? $p['thumb'] ?? '') : ($p['thumb'] ?? '')),
                ];
            }
            if (count($page) < 500) {
                break;
            }
        }

        return $rows;
    }

    public function recentlyAdded(int $since, int $max = 200): array
    {
        $rows = [];
        foreach (array_filter($this->call('get_recently_added', ['count' => min($max, 100)])['recently_added'] ?? [], 'is_array') as $r) {
            if ((int) ($r['added_at'] ?? 0) < $since) {
                continue;
            }
            $show = in_array($r['media_type'] ?? '', ['season', 'episode', 'show'], true);
            $rows[] = [
                'title' => (string) ($show ? ($r['grandparent_title'] ?: $r['parent_title'] ?: $r['title'] ?? '') : ($r['title'] ?? '')),
                'added_at' => gmdate('c', (int) $r['added_at']),
                'media_type' => $show ? 'show' : 'movie',
                'thumb' => (string) ($show ? ($r['grandparent_thumb'] ?? $r['parent_thumb'] ?? $r['thumb'] ?? '') : ($r['thumb'] ?? '')),
            ];
        }

        return $rows;
    }

    public function addedPoster(array $row): ?string
    {
        return self::posterPath((string) ($row['thumb'] ?? ''));
    }

    public function poster(string $path): ?array
    {
        if (! preg_match('~^tautulli:(/library/metadata/\d{1,12}/(?:thumb|art)/\d{1,12})$~', $path, $m)) {
            return null;
        }
        try {
            $response = $this->http->send('GET', '/api/v2', ['apikey' => $this->key, 'cmd' => 'pms_image_proxy', 'img' => $m[1], 'width' => 300, 'height' => 450, 'fallback' => 'poster'], headers: ['Accept' => 'image/*']);
        } catch (MediaServerException) {
            return null;
        }
        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return str_starts_with($type, 'image/') && $type !== 'image/svg+xml' ? ['body' => $response->body(), 'type' => $type] : null;
    }

    /** Only Plex's own poster paths, marked as Tautulli's. */
    private static function posterPath(string $thumb): ?string
    {
        return preg_match('~^/library/metadata/\d{1,12}/(thumb|art)/\d{1,12}$~', $thumb) ? 'tautulli:'.$thumb : null;
    }
}
