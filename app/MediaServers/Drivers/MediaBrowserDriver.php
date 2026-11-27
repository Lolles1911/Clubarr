<?php

namespace App\MediaServers\Drivers;

use App\MediaServers\Contracts\CreatesUsers;
use App\MediaServers\Contracts\MediaServerDriver;
use App\MediaServers\Data\LibraryData;
use App\MediaServers\Data\RemoteUser;
use App\MediaServers\Data\ServerInfo;
use App\MediaServers\MediaServerException;
use App\MediaServers\ServerHttp;
use App\Models\MediaServer;
use Throwable;

/**
 * Jellyfin and Emby share most of their API (both come from Media Browser).
 * The differences are in the two drivers that extend this one.
 */
abstract class MediaBrowserDriver implements CreatesUsers, MediaServerDriver
{
    protected ServerHttp $http;

    public function __construct(protected MediaServer $server)
    {
        $this->http = new ServerHttp($server->url, [
            'Authorization' => 'MediaBrowser Token="'.$server->api_key.'"',
            'X-Emby-Token' => $server->api_key,
        ], $server->name);
    }

    /** The ID a library has in a user's EnabledFolders. */
    abstract protected function folderPolicyId(array $folder): string;

    /** Makes the user with this password; returns the new user's ID. */
    abstract protected function newUser(string $username, string $password): string;

    public function test(): ServerInfo
    {
        $info = $this->http->json('GET', '/System/Info');
        if (empty($info['Id']) && empty($info['ServerName'])) {
            throw new MediaServerException(__(':name answered, but not like :type does. Check the address.', ['name' => $this->server->name, 'type' => $this->server->type->label()]));
        }

        return new ServerInfo((string) ($info['ServerName'] ?? $this->server->name), isset($info['Version']) ? (string) $info['Version'] : null, isset($info['Id']) ? (string) $info['Id'] : null);
    }

    public function libraries(): array
    {
        return array_values(array_map(fn (array $folder) => new LibraryData($this->folderPolicyId($folder), (string) ($folder['Name'] ?? ''), $folder['CollectionType'] ?? null),
            array_filter($this->http->json('GET', '/Library/MediaFolders')['Items'] ?? [], 'is_array')));
    }

    public function users(): array
    {
        return array_values(array_map(fn (array $user) => $this->remoteUser($user), array_filter($this->http->json('GET', '/Users'), 'is_array')));
    }

    public function createUser(string $username, string $password, ?array $libraryIds): RemoteUser
    {
        $id = $this->newUser($username, $password);
        try {
            $policy = $this->policy($id);
            $policy['IsAdministrator'] = false;
            $policy['IsDisabled'] = false;
            $this->http->send('POST', '/Users/'.rawurlencode($id).'/Policy', json: $this->withLibraries($policy, $libraryIds));
        } catch (Throwable $e) {
            // No half-made user with access to everything: remove it again.
            try {
                $this->http->send('DELETE', '/Users/'.rawurlencode($id));
            } catch (Throwable) {
                // Reported below.
            }

            throw $e instanceof MediaServerException ? $e : new MediaServerException($e->getMessage());
        }

        return new RemoteUser($id, $username);
    }

    public function setPassword(string $remoteId, string $password): void
    {
        $this->ordinaryUser($remoteId);
        $this->http->send('POST', '/Users/'.rawurlencode($remoteId).'/Password', json: ['CurrentPw' => '', 'NewPw' => $password, 'ResetPassword' => false]);
    }

    public function setLibraries(string $remoteId, ?array $libraryIds): void
    {
        $policy = $this->ordinaryUser($remoteId)['Policy'] ?? [];
        $this->http->send('POST', '/Users/'.rawurlencode($remoteId).'/Policy', json: $this->withLibraries($policy, $libraryIds));
    }

    public function disable(string $remoteId, string $username): void
    {
        $this->setDisabled($remoteId, true);
    }

    public function enable(string $remoteId, string $username, ?array $libraryIds): void
    {
        $this->setDisabled($remoteId, false);
    }

    public function delete(string $remoteId, string $username): void
    {
        $this->ordinaryUser($remoteId);
        $this->http->send('DELETE', '/Users/'.rawurlencode($remoteId));
    }

    private function setDisabled(string $remoteId, bool $disabled): void
    {
        $policy = $this->ordinaryUser($remoteId)['Policy'] ?? [];
        $policy['IsDisabled'] = $disabled;
        $this->http->send('POST', '/Users/'.rawurlencode($remoteId).'/Policy', json: $policy);
    }

    /** The user, refusing a server administrator. */
    protected function ordinaryUser(string $remoteId): array
    {
        $user = $this->http->json('GET', '/Users/'.rawurlencode($remoteId));
        if (! empty($user['Policy']['IsAdministrator'])) {
            throw new MediaServerException(__('Clubarr does not change administrators of :name.', ['name' => $this->server->name]), 403);
        }

        return $user;
    }

    protected function policy(string $remoteId): array
    {
        $policy = $this->http->json('GET', '/Users/'.rawurlencode($remoteId))['Policy'] ?? null;
        if (! is_array($policy)) {
            throw new MediaServerException(__(':name did not return the new user\'s settings.', ['name' => $this->server->name]));
        }

        return $policy;
    }

    /** @param array<int, string>|null $libraryIds */
    private function withLibraries(array $policy, ?array $libraryIds): array
    {
        $policy['EnableAllFolders'] = $libraryIds === null;
        $policy['EnabledFolders'] = $libraryIds === null ? [] : array_values(array_map('strval', $libraryIds));

        return $policy;
    }

    private function remoteUser(array $user): RemoteUser
    {
        return new RemoteUser((string) ($user['Id'] ?? ''), (string) ($user['Name'] ?? ''), null,
            ! empty($user['Policy']['IsAdministrator']), ! empty($user['Policy']['IsDisabled']));
    }

    /** Reads why a new user could not be made; a taken name is told apart. */
    protected function createFailed(MediaServerException $e, string $username): MediaServerException
    {
        $taken = $e->status === 400 && stripos($e->getMessage(), 'already') !== false;

        return $taken ? new MediaServerException(__('The user name ":name" is taken on :server. Choose another.', ['name' => $username, 'server' => $this->server->name]), 400, true) : $e;
    }
}
