<?php

namespace App\MediaServers\Drivers;

use App\MediaServers\Contracts\ListsRecentlyAdded;
use App\MediaServers\Contracts\MediaServerDriver;
use App\MediaServers\Contracts\SharesWithPlexAccounts;
use App\MediaServers\Data\LibraryData;
use App\MediaServers\Data\PlexIdentity;
use App\MediaServers\Data\RecentItem;
use App\MediaServers\Data\RemoteUser;
use App\MediaServers\Data\ServerInfo;
use App\MediaServers\MediaServerException;
use App\MediaServers\ServerHttp;
use App\Models\MediaServer;
use App\Services\SiteSettings;

/**
 * Plex: members have their own plex.tv account, and the server owner shares
 * libraries with it (plex.tv's sharing API, as Plex Web uses it). The key is
 * the owner's Plex token. Tokens always go in headers, never in addresses.
 */
class PlexDriver implements ListsRecentlyAdded, MediaServerDriver, SharesWithPlexAccounts
{
    private ServerHttp $local;

    private ServerHttp $plexTv;

    private ServerHttp $clients;

    public function __construct(private MediaServer $server)
    {
        $headers = self::plexHeaders() + ['X-Plex-Token' => $server->api_key];
        $this->local = new ServerHttp($server->url, $headers, $server->name);
        $this->plexTv = new ServerHttp('https://plex.tv', $headers, 'plex.tv');
        $this->clients = new ServerHttp('https://clients.plex.tv', $headers, 'plex.tv');
    }

    /** @return array<string, string> */
    public static function plexHeaders(): array
    {
        return [
            'X-Plex-Product' => 'Clubarr',
            'X-Plex-Version' => (string) config('clubarr.version'),
            'X-Plex-Client-Identifier' => app(SiteSettings::class)->plexClientId(),
            'X-Plex-Platform' => 'Web',
            'X-Plex-Device-Name' => 'Clubarr',
        ];
    }

    public function test(): ServerInfo
    {
        $root = $this->local->json('GET', '/')['MediaContainer'] ?? [];
        if (empty($root['machineIdentifier'])) {
            throw new MediaServerException(__(':name answered, but not like Plex does. Check the address.', ['name' => $this->server->name]));
        }
        $owner = $this->plexTv->json('GET', '/api/v2/user');
        $this->remember(['machine_id' => (string) $root['machineIdentifier'], 'owner_username' => (string) ($owner['username'] ?? '')]);

        return new ServerInfo((string) ($root['friendlyName'] ?? $this->server->name), isset($root['version']) ? (string) $root['version'] : null, (string) $root['machineIdentifier']);
    }

    /** Libraries with plex.tv's IDs, which the sharing API needs. */
    public function libraries(): array
    {
        $sections = $this->plexTv->json('GET', '/api/v2/servers/'.rawurlencode($this->machineId()))['librarySections'] ?? [];

        return array_values(array_map(fn (array $s) => new LibraryData((string) $s['id'], (string) ($s['title'] ?? ''), $s['type'] ?? null),
            array_filter($sections, fn ($s) => is_array($s) && isset($s['id']))));
    }

    /** Plex's recently added; a member's libraries are matched by name (plex.tv and the server number them differently). */
    public function recentlyAdded(?array $libraryIds, int $limit): array
    {
        $names = $libraryIds === null ? null : $this->server->libraries()->whereIn('external_id', $libraryIds)->pluck('name')->all();
        $found = $this->local->json('GET', '/library/recentlyAdded', ['X-Plex-Container-Start' => 0, 'X-Plex-Container-Size' => $limit * 3])['MediaContainer']['Metadata'] ?? [];
        $items = [];
        foreach (array_filter($found, 'is_array') as $item) {
            if ($names !== null && ! in_array((string) ($item['librarySectionTitle'] ?? ''), $names, true)) {
                continue;
            }
            $show = in_array($item['type'] ?? '', ['episode', 'season'], true);
            $thumb = (string) ($show ? ($item['grandparentThumb'] ?? $item['parentThumb'] ?? '') : ($item['thumb'] ?? ''));
            $title = (string) ($show ? ($item['grandparentTitle'] ?? $item['parentTitle'] ?? '') : ($item['title'] ?? ''));
            $key = (string) ($show ? ($item['grandparentRatingKey'] ?? $item['parentRatingKey'] ?? '') : ($item['ratingKey'] ?? ''));
            if ($title === '' || ! self::isPosterPath($thumb) || isset($items[$key]) || ! in_array($item['type'] ?? '', ['movie', 'episode', 'season'], true)) {
                continue;
            }
            $items[$key] = new RecentItem($thumb, $title, $show ? null : (isset($item['year']) ? (int) $item['year'] : null), (int) ($item['addedAt'] ?? 0));
        }

        return array_slice(array_values($items), 0, $limit);
    }

    public function poster(string $ref): ?array
    {
        if (! self::isPosterPath($ref)) {
            return null;
        }
        $response = $this->local->send('GET', '/photo/:/transcode', ['url' => $ref, 'width' => 300, 'height' => 450, 'minSize' => 1, 'upscale' => 1]);
        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true) ? ['type' => $type, 'body' => $response->body()] : null;
    }

    /** Only the server's own poster paths, e.g. /library/metadata/123/thumb/1699999999. */
    private static function isPosterPath(string $path): bool
    {
        return preg_match('~^/library/metadata/\d{1,12}/(thumb|art)/\d{1,12}$~', $path) === 1;
    }

    /** The plex.tv accounts the server is shared with. */
    public function users(): array
    {
        return array_map(function (array $share) {
            $invited = is_array($share['invited'] ?? null) ? $share['invited'] : [];

            return new RemoteUser((string) ($share['invitedId'] ?? $invited['id'] ?? ''), (string) ($invited['username'] ?? $invited['title'] ?? $share['invitedEmail'] ?? ''), $invited['email'] ?? $share['invitedEmail'] ?? null);
        }, $this->shares());
    }

    public function share(PlexIdentity $member, ?array $libraryIds): RemoteUser
    {
        $libraryIds ??= array_map(fn (LibraryData $l) => $l->id, $this->libraries());
        $response = $this->plexTv->send('POST', '/api/servers/'.rawurlencode($this->machineId()).'/shared_servers', json: [
            'server_id' => $this->machineId(),
            'shared_server' => ['library_section_ids' => array_map('intval', $libraryIds), 'invited_email' => $member->email ?: $member->username],
            'sharing_settings' => (object) [],
        ]);
        // A typed email has no plex.tv ID yet; plex.tv may tell it in its answer.
        $id = $member->id;
        if ($id === '' && preg_match('/invited_?[iI]d"?\s*[=:]\s*"?(\d+)/', $response->body(), $m)) {
            $id = $m[1];
        }

        return new RemoteUser($id, $member->username, $member->email);
    }

    public function acceptShare(string $memberToken): bool
    {
        $asMember = ['X-Plex-Token' => $memberToken];
        $owner = (string) $this->server->setting('owner_username', '');
        foreach ($this->clients->json('GET', '/api/v2/shared_servers/invites/received/pending', headers: $asMember) as $invite) {
            $from = is_array($invite['owner'] ?? null) ? $invite['owner'] : [];
            $shared = $invite['sharedServers'][0]['id'] ?? null;
            $ours = collect($invite['sharedServers'] ?? [])->contains(fn ($s) => ($s['machineIdentifier'] ?? null) === $this->machineId());
            if ($shared !== null && ($ours || ($owner !== '' && in_array($owner, [$from['username'] ?? null, $from['title'] ?? null], true)))) {
                $this->clients->send('POST', '/api/v2/shared_servers/'.rawurlencode((string) $shared).'/accept', headers: $asMember);

                return true;
            }
        }

        return false;
    }

    /** Plex has no "disabled": the share is removed, and made again on enable. */
    public function disable(string $remoteId, string $username): void
    {
        $this->unshare($remoteId);
    }

    public function enable(string $remoteId, string $username, ?array $libraryIds): void
    {
        $this->share(new PlexIdentity($remoteId, $username, null), $libraryIds);
    }

    /** The member keeps their plex.tv account; only the share goes. */
    public function delete(string $remoteId, string $username): void
    {
        $this->unshare($remoteId);
    }

    private function unshare(string $remoteId): void
    {
        foreach ($this->shares() as $share) {
            if ((string) ($share['invitedId'] ?? $share['invited']['id'] ?? '') === $remoteId && isset($share['id'])) {
                $this->clients->send('DELETE', '/api/v2/shared_servers/'.rawurlencode((string) $share['id']));
            }
        }
    }

    /** @return list<array<string, mixed>> the accepted shares of this server */
    private function shares(): array
    {
        $machine = $this->machineId();

        return array_values(array_filter($this->clients->json('GET', '/api/v2/shared_servers/owned/accepted'),
            fn ($share) => is_array($share) && ($share['machineIdentifier'] ?? null) === $machine));
    }

    private function machineId(): string
    {
        $id = (string) $this->server->setting('machine_id', '');
        if ($id === '') {
            $id = (string) ($this->local->json('GET', '/identity')['MediaContainer']['machineIdentifier'] ?? '');
            if ($id === '') {
                throw new MediaServerException(__(':name did not tell its machine ID. Check the address.', ['name' => $this->server->name]));
            }
            $this->remember(['machine_id' => $id]);
        }

        return $id;
    }

    /** @param array<string, string> $values */
    private function remember(array $values): void
    {
        $this->server->settings = array_merge($this->server->settings ?? [], $values);
        if ($this->server->exists) {
            $this->server->saveQuietly();
        }
    }
}
