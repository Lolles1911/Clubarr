<?php

namespace App\MediaServers\Contracts;

use App\MediaServers\Data\RecentItem;

/** Servers that can tell what was added lately, with posters (for the members' page). */
interface ListsRecentlyAdded extends ShowsPosters
{
    /**
     * The newest films and shows (a show once, by its newest episode).
     *
     * @param  array<int, string>|null  $libraryIds  the member's libraries (null = all)
     * @return list<RecentItem>
     */
    public function recentlyAdded(?array $libraryIds, int $limit): array;
}
