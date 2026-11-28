<?php

namespace App\MediaServers\Drivers;

use App\MediaServers\Contracts\MediaServerDriver;
use App\MediaServers\Contracts\ReadsWatchHistory;
use App\MediaServers\Contracts\ShowsPosters;
use App\MediaServers\Contracts\UsesClaimLinks;
use App\MediaServers\Data\Claim;
use App\MediaServers\Data\LibraryData;
use App\MediaServers\Data\RecentItem;
use App\MediaServers\Data\RemoteUser;
use App\MediaServers\Data\ServerInfo;
use App\MediaServers\MediaServerException;
use App\MediaServers\ServerHttp;
use App\Models\MediaServer;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Silo (API v2): the member makes their own user on a one-time claim link.
 * Account changes use Silo's edit guard (ETag + If-Match), and Silo
 * administrators are never changed.
 */
class SiloDriver implements MediaServerDriver, ReadsWatchHistory, ShowsPosters, UsesClaimLinks
{
    private ServerHttp $http;

    private ?string $profileId = null;

    public function __construct(private MediaServer $server)
    {
        $this->http = new ServerHttp($server->url, ['Authorization' => 'Bearer '.$server->api_key], $server->name);
    }

    public function test(): ServerInfo
    {
        // Needs admin rights, so a key without them shows up here.
        $this->http->json('GET', '/api/v2/admin/invitations/capabilities');

        return new ServerInfo($this->server->name);
    }

    public function libraries(): array
    {
        return array_values(array_map(fn (array $l) => new LibraryData((string) $l['id'], (string) ($l['name'] ?? '')),
            array_filter($this->http->json('GET', '/api/v2/libraries')['items'] ?? [], fn ($l) => is_array($l) && isset($l['id']))));
    }

    public function users(): array
    {
        return array_map(fn (array $a) => $this->remoteUser($a), $this->pages('/api/v2/admin/users'));
    }

    public function createClaim(?array $libraryIds): Claim
    {
        $body = ['delivery' => 'link', 'role' => 'user', 'note' => 'Clubarr'];
        if ($libraryIds !== null) {
            $body['library_ids'] = array_values(array_map('strval', $libraryIds));
        }
        $answer = $this->http->json('POST', '/api/v2/admin/invitations', json: $body);
        $id = $answer['invitation']['id'] ?? null;
        $url = (string) ($answer['claim_url'] ?? '');
        if ($id === null || ! str_starts_with($url, 'http')) {
            throw new MediaServerException(__(':name did not return an invitation link.', ['name' => $this->server->name]));
        }

        return new Claim((string) $id, $url);
    }

    public function claimedUser(string $claimId): ?RemoteUser
    {
        foreach ($this->pages('/api/v2/admin/invitations') as $invitation) {
            if ((string) ($invitation['id'] ?? '') === $claimId && ($invitation['status'] ?? '') === 'accepted' && ! empty($invitation['accepted_user_id'])) {
                return $this->remoteUser($this->http->json('GET', '/api/v2/admin/users/'.rawurlencode((string) $invitation['accepted_user_id'])));
            }
        }

        return null;
    }

    public function passwordResetLink(string $remoteId): string
    {
        $this->ordinaryUser($remoteId);
        $url = (string) ($this->http->json('POST', '/api/v2/admin/users/'.rawurlencode($remoteId).'/password-reset', json: ['delivery' => 'link'])['reset_url'] ?? '');
        if (! str_starts_with($url, 'http')) {
            throw new MediaServerException(__(':name did not return a password link.', ['name' => $this->server->name]));
        }

        return $url;
    }

    public function disable(string $remoteId, string $username): void
    {
        $this->guarded('PUT', $remoteId, ['enabled' => false]);
    }

    public function enable(string $remoteId, string $username, ?array $libraryIds): void
    {
        $this->guarded('PUT', $remoteId, ['enabled' => true]);
    }

    public function delete(string $remoteId, string $username): void
    {
        $this->guarded('DELETE', $remoteId);
    }

    /** A change under Silo's edit guard; a stale ETag is read again once. */
    private function guarded(string $method, string $remoteId, ?array $body = null): void
    {
        for ($attempt = 1; ; $attempt++) {
            $response = $this->http->send('GET', '/api/v2/admin/users/'.rawurlencode($remoteId));
            if ((string) ($response->json('role') ?? '') === 'admin') {
                throw new MediaServerException(__('Clubarr does not change administrators of :name.', ['name' => $this->server->name]), 403);
            }
            $etag = (string) $response->header('ETag');
            if ($etag === '') {
                throw new MediaServerException(__(':name sent no ETag, so the user can\'t be changed safely.', ['name' => $this->server->name]));
            }
            try {
                $this->http->send($method, '/api/v2/admin/users/'.rawurlencode($remoteId), json: $body, headers: ['If-Match' => $etag]);

                return;
            } catch (MediaServerException $e) {
                if ($e->status !== 412 || $attempt >= 2) {
                    throw $e;
                }
            }
        }
    }

    private function ordinaryUser(string $remoteId): void
    {
        if (($this->http->json('GET', '/api/v2/admin/users/'.rawurlencode($remoteId))['role'] ?? '') === 'admin') {
            throw new MediaServerException(__('Clubarr does not change administrators of :name.', ['name' => $this->server->name]), 403);
        }
    }

    /** @return list<array<string, mixed>> every item of a cursor-paged list */
    /**
     * Every play Silo recorded for the user (admin:monitor:read), newest
     * first: real watching time where Silo measured it.
     */
    public function watched(string $remoteId, int $limit = 300): array
    {
        $plays = [];
        foreach ($this->history($remoteId, $limit) as $play) {
            $started = strtotime((string) ($play['started_at'] ?? '')) ?: 0;
            if ($started === 0) {
                continue;
            }
            $ended = strtotime((string) ($play['ended_at'] ?? '')) ?: $started;
            $duration = (float) ($play['duration_seconds'] ?? 0);
            $seconds = ! empty($play['measured']) && isset($play['played_seconds']) ? (float) $play['played_seconds'] : (float) ($play['watched_seconds'] ?? max(0, $ended - $started));
            $seconds = $duration > 0 ? min($seconds, $duration) : $seconds;
            $episode = ($play['media_type'] ?? '') === 'episode';
            $plays[] = [
                'started_at' => gmdate('Y-m-d\TH:i:s\Z', $started),
                'duration_ms' => (int) round(1000 * max(0, $seconds)),
                'media_type' => $episode ? 'episode' : (($play['media_type'] ?? '') === 'movie' ? 'movie' : 'other'),
                'media_title' => (string) ($play['media_title'] ?? ''),
                'show_title' => $episode ? (string) ($play['series_title'] ?? '') : null,
                'platform' => trim((string) ($play['client_name'] ?? '')) ?: trim((string) ($play['device_platform'] ?? '')),
                'percent_complete' => ! empty($play['completed']) ? 100 : ($duration > 0 ? (int) round(100 * min(1, (float) ($play['watched_seconds'] ?? 0) / $duration)) : 0),
                'watched' => ! empty($play['completed']),
                'item' => (string) ($play['media_item_id'] ?? ''),
            ];
        }

        return $plays;
    }

    /** Silo has no "similar" in its API (yet): none. */
    public function similar(string $remoteId, string $itemId, int $limit = 12): array
    {
        return [];
    }

    /** From the history: what was left part-way (3 to 90 percent) and not finished since. */
    public function resume(string $remoteId, int $limit = 12): array
    {
        $seen = $items = [];
        foreach ($this->watched($remoteId, 200) as $play) {
            $title = $play['media_type'] === 'episode' ? (string) $play['show_title'] : $play['media_title'];
            if ($title === '' || isset($seen[$title])) {
                continue;
            }
            $seen[$title] = true;
            if (! $play['watched'] && $play['percent_complete'] >= 3 && $play['percent_complete'] <= 90 && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $play['item'])) {
                $items[] = new RecentItem($play['item'], $title, null, $play['percent_complete']);
            }
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * A catalog item's poster (a show's for an episode). Catalog reads act as
     * the key owner's main profile; Silo's own signed image routes get the
     * key, any other address (object storage) is fetched without it, and
     * never with redirects.
     */
    public function poster(string $ref): ?array
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $ref) || ($profile = $this->profileId()) === '') {
            return null;
        }
        $item = $this->http->json('GET', '/api/v2/catalog/items/'.$ref, ['image_size' => 'small'], headers: ['X-Profile-Id' => $profile]);
        $url = (string) ($item['series_poster_url'] ?? '') ?: (string) ($item['poster_url'] ?? '');
        try {
            if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
                $response = $this->http->send('GET', $url, headers: ['Accept' => 'image/*']);
            } elseif (preg_match('~^https://~i', $url)) {
                $response = Http::timeout(10)->withoutRedirecting()->withHeaders(['Accept' => 'image/*'])->get($url);
            } else {
                return null;
            }
        } catch (Throwable) {
            return null;
        }
        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return $response->status() === 200 && in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true) ? ['type' => $type, 'body' => $response->body()] : null;
    }

    /** @return list<array<string, mixed>> the user's plays, newest first, only theirs whatever the filter did */
    private function history(string $remoteId, int $max): array
    {
        $plays = [];
        $cursor = null;
        do {
            $page = $this->http->json('GET', '/api/v2/admin/playback-history', ['user_id' => $remoteId, 'limit' => min(200, max(1, $max - count($plays)))] + ($cursor !== null ? ['cursor' => $cursor] : []));
            foreach ($page['items'] ?? [] as $play) {
                if (is_array($play) && (string) ($play['user_id'] ?? '') === $remoteId) {
                    $plays[] = $play;
                }
            }
            $cursor = ! empty($page['page']['has_more']) ? ($page['page']['next_cursor'] ?? null) : null;
        } while ($cursor !== null && count($plays) < $max);

        return $plays;
    }

    /** The key owner's main profile without a PIN, which catalog reads act as; "" when there is none. */
    private function profileId(): string
    {
        if ($this->profileId === null) {
            $this->profileId = '';
            foreach ($this->http->json('GET', '/api/v2/profiles')['items'] ?? [] as $profile) {
                if (is_array($profile) && ! empty($profile['is_primary']) && empty($profile['has_pin'])) {
                    $this->profileId = (string) ($profile['id'] ?? '');
                }
            }
        }

        return $this->profileId;
    }

    private function pages(string $path): array
    {
        $items = [];
        $cursor = null;
        do {
            $page = $this->http->json('GET', $path, ['limit' => 200] + ($cursor !== null ? ['cursor' => $cursor] : []));
            foreach ($page['items'] ?? [] as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            $cursor = ! empty($page['page']['has_more']) ? ($page['page']['next_cursor'] ?? null) : null;
        } while ($cursor !== null && count($items) < 50000);

        return $items;
    }

    private function remoteUser(array $account): RemoteUser
    {
        return new RemoteUser((string) ($account['id'] ?? ''), (string) ($account['username'] ?? ''), $account['email'] ?? null,
            ($account['role'] ?? '') === 'admin', ! ($account['enabled'] ?? true));
    }
}
