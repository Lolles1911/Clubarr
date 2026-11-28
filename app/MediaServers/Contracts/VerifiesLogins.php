<?php

namespace App\MediaServers\Contracts;

use App\MediaServers\Data\RemoteUser;

/**
 * Servers that can check a user name and password (Jellyfin, Emby), so a
 * member can prove a user on the server is theirs. The password is only
 * passed on to the server, never kept.
 */
interface VerifiesLogins
{
    /** The user, when the name and password are right; null when not. */
    public function verifyLogin(string $username, string $password): ?RemoteUser;
}
