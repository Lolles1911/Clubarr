<?php

namespace App\MediaServers\Contracts;

use App\MediaServers\Data\LibraryData;
use App\MediaServers\Data\RemoteUser;
use App\MediaServers\Data\ServerInfo;

/**
 * What every media server can do. How a member gets a user differs per
 * server, and is one of the other interfaces in this folder:
 * CreatesUsers (Jellyfin, Emby), SharesWithPlexAccounts (Plex) or
 * UsesClaimLinks (Silo).
 *
 * Every method throws App\MediaServers\MediaServerException on failure, and
 * refuses to change a server administrator.
 */
interface MediaServerDriver
{
    /** Checks the address and key; returns what the server calls itself. */
    public function test(): ServerInfo;

    /** @return list<LibraryData> */
    public function libraries(): array;

    /** @return list<RemoteUser> */
    public function users(): array;

    /** Stops the user from watching, keeping the user. */
    public function disable(string $remoteId, string $username): void;

    /** Lets a disabled user watch again, with these libraries (null = all). */
    public function enable(string $remoteId, string $username, ?array $libraryIds): void;

    public function delete(string $remoteId, string $username): void;
}
