<?php

namespace App\MediaServers\Contracts;

use App\MediaServers\Data\RemoteUser;

/** Servers where Clubarr makes the user with a username and password (Jellyfin, Emby). */
interface CreatesUsers
{
    /** @param array<int, string>|null $libraryIds null = all libraries */
    public function createUser(string $username, string $password, ?array $libraryIds): RemoteUser;

    public function setPassword(string $remoteId, string $password): void;

    /** @param array<int, string>|null $libraryIds */
    public function setLibraries(string $remoteId, ?array $libraryIds): void;
}
