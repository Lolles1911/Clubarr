<?php

namespace App\Integrations;

/**
 * Where Clubarr learns what members watch: Tracearr or Tautulli. Rows use
 * Tracearr's field names (Tautulli's are translated): server_type,
 * username, media_type, show_title, media_title, started_at, duration_ms,
 * percent_complete, watched, player, platform, device, poster_url.
 */
interface WatchSource
{
    /** @return list<array<string, mixed>> what plays now (with 'id' to stop it) */
    public function streams(): array;

    public function terminate(string $sessionId, string $reason): void;

    /** @return list<array<string, mixed>> plays since $since, newest first */
    public function history(int $since, int $max = 3000): array;

    /** @return list<array<string, mixed>> films and shows added since $since */
    public function recentlyAdded(int $since, int $max = 200): array;

    /** The poster path of a row from recentlyAdded(), for poster(). */
    public function addedPoster(array $row): ?string;

    /** @return array{body: string, type: string}|null a poster by a path this source gave */
    public function poster(string $path): ?array;
}
