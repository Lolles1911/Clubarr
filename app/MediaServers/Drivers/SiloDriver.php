<?php

namespace App\MediaServers\Drivers;

use App\MediaServers\Contracts\MediaServerDriver;
use App\MediaServers\Contracts\UsesClaimLinks;
use App\MediaServers\Data\Claim;
use App\MediaServers\Data\LibraryData;
use App\MediaServers\Data\RemoteUser;
use App\MediaServers\Data\ServerInfo;
use App\MediaServers\MediaServerException;
use App\MediaServers\ServerHttp;
use App\Models\MediaServer;

/**
 * Silo (API v2): the member makes their own user on a one-time claim link.
 * Account changes use Silo's edit guard (ETag + If-Match), and Silo
 * administrators are never changed.
 */
class SiloDriver implements MediaServerDriver, UsesClaimLinks
{
    private ServerHttp $http;

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
