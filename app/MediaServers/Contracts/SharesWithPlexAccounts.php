<?php

namespace App\MediaServers\Contracts;

use App\MediaServers\Data\PlexIdentity;
use App\MediaServers\Data\RemoteUser;

/** Plex: members keep their own plex.tv account; the server is shared with it. */
interface SharesWithPlexAccounts
{
    /** @param array<int, string>|null $libraryIds null = all libraries */
    public function share(PlexIdentity $member, ?array $libraryIds): RemoteUser;

    /** Accepts the share for the member, with their own plex.tv token (used once, never stored). */
    public function acceptShare(string $memberToken): bool;
}
