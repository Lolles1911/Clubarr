<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\MediaServers\Contracts\ListsRecentlyAdded;
use App\MediaServers\Data\RecentItem;
use App\Models\MediaServer;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "New on the server" for a member: the newest films and shows from the
 * libraries they have, read from the media servers themselves (no Tracearr
 * needed). The lists are fetched in the background (warm(), from the server
 * check every 5 minutes, at most every 30 minutes per list) and kept as
 * plain arrays; showing the page never waits for a media server. Posters go
 * through Clubarr (/posters/s/{key}), and only to members of that server.
 */
class NewOnServers
{
    public const LIMIT = 24;

    /** @return list<array{key: string, title: string, year: int|null, server: string}> */
    public function forMember(User $user): array
    {
        $rows = [];
        $accounts = $user->serverAccounts()->with('mediaServer')->where('status', AccountStatus::Active)->get();
        foreach ($accounts as $account) {
            $server = $account->mediaServer;
            if ($server->is_enabled === false || ! $server->type->can(ListsRecentlyAdded::class)) {
                continue;
            }
            $libraries = $account->library_ids === null ? null : array_values(array_map('strval', $account->library_ids));
            foreach (Cache::get(self::cacheKey($server->id, $libraries), []) as $item) {
                $rows[] = $item + ['server_id' => $server->id, 'server' => $server->name];
            }
        }
        usort($rows, fn ($a, $b) => $b['added'] <=> $a['added']);
        $allowed = Cache::get('clubarr:server-posters', []);
        $out = [];
        foreach (array_slice($rows, 0, self::LIMIT) as $row) {
            $key = substr(hash('sha256', $row['server_id'].'|'.$row['ref']), 0, 24);
            $allowed[$key] = [$row['server_id'], $row['ref']];
            $out[] = ['key' => $key, 'title' => $row['title'], 'year' => $row['year'], 'server' => $row['server']];
        }
        Cache::put('clubarr:server-posters', array_slice($allowed, -2000, null, true), 3 * 86400);

        return $out;
    }

    /** The poster behind a key, for a user who has access to that server. */
    public function poster(string $key, User $user): ?array
    {
        [$serverId, $ref] = Cache::get('clubarr:server-posters', [])[$key] ?? [null, null];
        if ($serverId === null || (! $user->is_admin && ! $user->serverAccounts()->where('media_server_id', $serverId)->where('status', AccountStatus::Active)->exists())) {
            return null;
        }
        $image = Cache::remember('clubarr:server-poster:'.$key, 86400, function () use ($serverId, $ref) {
            try {
                $driver = MediaServer::find($serverId)?->driver();
                $found = $driver instanceof ListsRecentlyAdded ? $driver->poster((string) $ref) : null;
            } catch (Throwable) {
                $found = null;
            }

            return $found ? ['type' => $found['type'], 'body' => base64_encode($found['body'])] : false;
        });

        return is_array($image) ? ['type' => $image['type'], 'body' => base64_decode($image['body'])] : null;
    }

    /** Fetches the lists of a server for every set of libraries its members have, when older than 30 minutes. */
    public function warm(MediaServer $server): void
    {
        if ($server->is_enabled === false || ! $server->type->can(ListsRecentlyAdded::class)) {
            return;
        }
        $sets = $server->accounts()->where('status', AccountStatus::Active)->pluck('library_ids')
            ->map(fn ($ids) => $ids === null ? null : array_values(array_map('strval', (array) $ids)))->unique(fn ($ids) => json_encode($ids));
        foreach ($sets as $libraries) {
            $key = self::cacheKey($server->id, $libraries);
            if (! Cache::add($key.':fresh', 1, 1800)) {
                continue; // fetched less than 30 minutes ago
            }
            try {
                $driver = $server->driver();
                $items = $driver instanceof ListsRecentlyAdded ? array_map(fn (RecentItem $i) => $i->toArray(), $driver->recentlyAdded($libraries, self::LIMIT)) : [];
                Cache::put($key, $items, 86400);
            } catch (Throwable) {
                // kept: the last list, until it expires
            }
        }
    }

    private static function cacheKey(int $serverId, ?array $libraries): string
    {
        return 'clubarr:recent:'.$serverId.':'.md5((string) json_encode($libraries));
    }
}
