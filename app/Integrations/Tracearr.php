<?php

namespace App\Integrations;

use App\MediaServers\MediaServerException;
use App\MediaServers\ServerHttp;
use App\Services\SiteSettings;

/**
 * Tracearr's public API (a "trr_pub_" key, Settings → Data & API in
 * Tracearr): what plays now, stopping a stream, plays, and what was added.
 * Tracearr watches Plex, Jellyfin and Emby (and Silo from Tracearr 3.3).
 */
class Tracearr
{
    private ServerHttp $http;

    public function __construct(string $url, string $key)
    {
        $this->http = new ServerHttp($url, ['Authorization' => 'Bearer '.$key], 'Tracearr');
    }

    /** The client from Settings, or null when Tracearr is not set up. */
    public static function fromSettings(): ?self
    {
        $settings = app(SiteSettings::class);
        $url = (string) $settings->get('tracearr_url');
        $key = (string) $settings->get('tracearr_api_key');

        return $url !== '' && $key !== '' ? new self($url, $key) : null;
    }

    /** @return list<array<string, mixed>> the media servers Tracearr watches */
    public function servers(): array
    {
        return array_values(array_filter($this->http->json('GET', '/api/v2/public/servers')['data'] ?? [], 'is_array'));
    }

    /** @return list<array<string, mixed>> every stream now: id, server_type, username, media_title, show_title, started_at, device, player */
    public function streams(): array
    {
        return array_values(array_filter($this->http->json('GET', '/api/v2/public/streams')['data'] ?? [], 'is_array'));
    }

    /** Stops a stream; Plex shows $reason on the screen. */
    public function terminate(string $sessionId, string $reason): void
    {
        $this->http->send('POST', '/api/v1/public/streams/'.rawurlencode($sessionId).'/terminate', json: ['reason' => mb_substr($reason, 0, 500)]);
    }

    /** @return list<array<string, mixed>> plays since $since, newest first, at most $max */
    public function history(int $since, int $max = 3000): array
    {
        return $this->pages('/api/v2/public/history', ['since' => gmdate('Y-m-d\TH:i:s\Z', $since)], $max);
    }

    /** @return list<array<string, mixed>> movies and shows added since $since, newest first */
    public function recentlyAdded(int $since, int $max = 200): array
    {
        $rows = [];
        $cursor = null;
        do {
            $page = $this->http->json('GET', '/api/v2/public/recently-added', array_filter(['pageSize' => 100, 'cursor' => $cursor]));
            foreach ($page['data'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if ((strtotime((string) ($row['added_at'] ?? '')) ?: 0) < $since) {
                    return $rows;
                }
                if (empty($row['removed_at']) && in_array($row['media_type'] ?? '', ['movie', 'show'], true)) {
                    $rows[] = $row;
                }
            }
            $cursor = $page['meta']['nextCursor'] ?? null;
        } while ($cursor !== null && count($rows) < $max);

        return $rows;
    }

    /**
     * A poster through Tracearr's image proxy. Only that path is fetched, so
     * this can't be used to reach anything else.
     *
     * @return array{body: string, type: string}|null
     */
    public function poster(string $path): ?array
    {
        if (! str_starts_with($path, '/api/v1/images/proxy?')) {
            return null;
        }
        try {
            $response = $this->http->send('GET', $path, headers: ['Accept' => 'image/*']);
        } catch (MediaServerException) {
            return null;
        }
        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return str_starts_with($type, 'image/') && $type !== 'image/svg+xml' ? ['body' => $response->body(), 'type' => $type] : null;
    }

    /** @return list<array<string, mixed>> */
    private function pages(string $path, array $query, int $max): array
    {
        $rows = [];
        $cursor = null;
        do {
            $page = $this->http->json('GET', $path, array_filter($query + ['pageSize' => 100, 'cursor' => $cursor]));
            foreach ($page['data'] ?? [] as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
            $cursor = $page['meta']['nextCursor'] ?? null;
        } while ($cursor !== null && count($rows) < $max);

        return $rows;
    }
}
