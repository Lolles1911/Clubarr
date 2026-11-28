<?php

namespace App\Integrations;

use App\MediaServers\MediaServerException;
use App\MediaServers\ServerHttp;
use App\Services\SiteSettings;

/**
 * Overseerr / Jellyseerr. The API key acts as Seerr's owner; requests are
 * made as the member (X-API-User), so Seerr's own permissions and
 * auto-approval apply to them.
 */
class Seerr
{
    private ServerHttp $http;

    public function __construct(string $url, string $key)
    {
        $this->http = new ServerHttp(rtrim($url, '/').'/api/v1', ['X-Api-Key' => $key], 'Seerr');
    }

    public static function fromSettings(): ?self
    {
        $settings = app(SiteSettings::class);
        $url = (string) $settings->get('seerr_url');
        $key = (string) $settings->get('seerr_api_key');

        return $url !== '' && $key !== '' ? new self($url, $key) : null;
    }

    /** Seerr's version; checks the address and key. */
    public function version(): string
    {
        // /settings/main needs the key (unlike /status).
        $this->http->json('GET', '/settings/main');

        return (string) ($this->http->json('GET', '/status')['version'] ?? '?');
    }

    /** 'plex', 'jellyfin' or 'emby': the media server Seerr is connected to. */
    public function mediaServerType(): ?string
    {
        $type = (int) ($this->http->json('GET', '/settings/main')['mediaServerType'] ?? 0);

        return [1 => 'plex', 2 => 'jellyfin', 3 => 'emby'][$type] ?? null;
    }

    /** Seerr's user for a Jellyfin/Emby user ID or a plex.tv account ID; imported into Seerr when missing. */
    public function userFor(string $kind, string $remoteId): ?int
    {
        $found = $this->findUser($kind, $remoteId);
        if ($found !== null) {
            return $found;
        }
        // Jellyfin's import answers with a list, Plex's with {createdUsers: [...]}.
        $answer = $kind === 'plex'
            ? $this->http->json('POST', '/user/import-from-plex', json: ['plexIds' => [$remoteId]])
            : $this->http->json('POST', '/user/import-from-jellyfin', json: ['jellyfinUserIds' => [$remoteId]]);
        $created = array_is_list($answer) ? $answer : ($answer['createdUsers'] ?? []);

        return isset($created[0]['id']) ? (int) $created[0]['id'] : $this->findUser($kind, $remoteId);
    }

    private function findUser(string $kind, string $remoteId): ?int
    {
        $field = $kind === 'plex' ? 'plexId' : 'jellyfinUserId';
        for ($skip = 0; $skip < 5000; $skip += 100) {
            $results = $this->http->json('GET', '/user', ['take' => 100, 'skip' => $skip])['results'] ?? [];
            foreach ($results as $user) {
                if (is_array($user) && (string) ($user[$field] ?? '') === $remoteId) {
                    return (int) $user['id'];
                }
            }
            if (count($results) < 100) {
                return null;
            }
        }

        return null;
    }

    /** @return list<array{type: string, tmdb: int, title: string, year: ?int, poster: ?string, state: string}> */
    public function search(int $user, string $query): array
    {
        $answer = $this->http->json('GET', '/search', ['query' => $query, 'page' => 1], headers: ['X-API-User' => (string) $user]);
        $rows = [];
        foreach ($answer['results'] ?? [] as $result) {
            if (is_array($result) && in_array($result['mediaType'] ?? '', ['movie', 'tv'], true)) {
                $rows[] = $this->row($result['mediaType'], $result);
            }
        }

        return $rows;
    }

    /** @return list<array{type: string, tmdb: int, title: string, status: string, date: string}> the member's requests, newest first */
    public function requestsOf(int $user, int $count = 20): array
    {
        $answer = $this->http->json('GET', '/request', ['take' => $count, 'skip' => 0, 'filter' => 'all', 'sort' => 'added', 'requestedBy' => $user], headers: ['X-API-User' => (string) $user]);
        $rows = [];
        foreach ($answer['results'] ?? [] as $request) {
            if (! is_array($request)) {
                continue;
            }
            $media = (int) ($request['media']['status'] ?? 1);
            $rows[] = [
                'type' => (string) ($request['media']['mediaType'] ?? $request['type'] ?? ''),
                'tmdb' => (int) ($request['media']['tmdbId'] ?? 0),
                'title' => '',
                'date' => (string) ($request['createdAt'] ?? ''),
                'status' => match (true) {
                    (int) ($request['status'] ?? 0) === 3 => 'declined',
                    $media === 5 => 'available',
                    $media === 4 => 'partial',
                    (int) ($request['status'] ?? 0) === 1 => 'pending',
                    default => 'approved',
                },
            ];
        }

        return $rows;
    }

    /** A title's name and poster by its TMDB ID; null when Seerr doesn't know it. */
    public function title(string $type, int $tmdb): ?array
    {
        if (! in_array($type, ['movie', 'tv'], true)) {
            return null;
        }
        try {
            return $this->row($type, $this->http->json('GET', '/'.$type.'/'.$tmdb));
        } catch (MediaServerException $e) {
            if ($e->status === 404) {
                return null;
            }

            throw $e;
        }
    }

    /** Requests a movie, or every season of a show, as the member. */
    public function request(int $user, string $type, int $tmdb): void
    {
        $body = ['mediaType' => $type, 'mediaId' => $tmdb, 'is4k' => false] + ($type === 'tv' ? ['seasons' => 'all'] : []);
        $this->http->send('POST', '/request', json: $body, headers: ['X-API-User' => (string) $user]);
    }

    /** @return array{type: string, tmdb: int, title: string, year: ?int, poster: ?string, state: string} */
    private function row(string $type, array $item): array
    {
        $date = (string) ($item['releaseDate'] ?? $item['firstAirDate'] ?? '');
        $poster = (string) ($item['posterPath'] ?? '');
        $state = match ((int) ($item['mediaInfo']['status'] ?? 1)) {
            5 => 'available', 4 => 'partial', 3 => 'processing', 2 => 'requested', default => 'none',
        };

        return [
            'type' => $type,
            'tmdb' => (int) ($item['id'] ?? 0),
            'title' => (string) ($item['title'] ?? $item['name'] ?? ''),
            'year' => preg_match('/^\d{4}/', $date, $m) ? (int) $m[0] : null,
            // Only TMDB's own file names: shown through /tmdb/{path}.
            'poster' => preg_match('~^/[A-Za-z0-9._-]+\.(jpg|png)$~', $poster) ? $poster : null,
            'state' => $state,
        ];
    }
}
