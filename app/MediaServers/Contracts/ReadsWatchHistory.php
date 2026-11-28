<?php

namespace App\MediaServers\Contracts;

use App\MediaServers\Data\RecentItem;

/**
 * Servers that know what a user watched (Jellyfin, Emby), for members'
 * numbers when there is no Tracearr or Tautulli. Rougher than those: one
 * play per title, on the day it was last watched.
 */
interface ReadsWatchHistory
{
    /**
     * Titles the user finished, newest first, shaped like Tracearr's plays
     * (started_at, duration_ms, media_type, media_title, show_title).
     *
     * @return list<array<string, mixed>>
     */
    public function watched(string $remoteId, int $limit = 300): array;

    /**
     * What the user is in the middle of; RecentItem::$addedAt holds how far (percent).
     *
     * @return list<RecentItem>
     */
    public function resume(string $remoteId, int $limit = 12): array;

    /**
     * Titles like one the user watched (its "item" from watched()), that they can see.
     *
     * @return list<RecentItem>
     */
    public function similar(string $remoteId, string $itemId, int $limit = 12): array;
}
