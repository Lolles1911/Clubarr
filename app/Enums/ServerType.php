<?php

namespace App\Enums;

use App\MediaServers\Contracts\MediaServerDriver;
use App\MediaServers\Drivers\EmbyDriver;
use App\MediaServers\Drivers\JellyfinDriver;
use App\MediaServers\Drivers\PlexDriver;
use App\MediaServers\Drivers\SiloDriver;

/**
 * The media servers Clubarr can invite to. A new kind of server is a new
 * case here and a driver in app/MediaServers/Drivers.
 */
enum ServerType: string
{
    case Jellyfin = 'jellyfin';
    case Emby = 'emby';
    case Plex = 'plex';
    case Silo = 'silo';

    public function label(): string
    {
        return match ($this) {
            self::Jellyfin => 'Jellyfin',
            self::Emby => 'Emby',
            self::Plex => 'Plex',
            self::Silo => 'Silo',
        };
    }

    /** @return class-string<MediaServerDriver> */
    public function driver(): string
    {
        return match ($this) {
            self::Jellyfin => JellyfinDriver::class,
            self::Emby => EmbyDriver::class,
            self::Plex => PlexDriver::class,
            self::Silo => SiloDriver::class,
        };
    }

    /** Whether this kind of server works a certain way (an interface in App\MediaServers\Contracts). */
    public function can(string $contract): bool
    {
        return is_subclass_of($this->driver(), $contract);
    }

    /**
     * Where to get the app: [label, address]. On a TV, the app is found by
     * its name in the TV's own app store.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function apps(): array
    {
        return match ($this) {
            self::Jellyfin => [['iPhone & iPad', 'https://apps.apple.com/app/jellyfin-mobile/id1480192618'], ['Android', 'https://play.google.com/store/apps/details?id=org.jellyfin.mobile']],
            self::Emby => [['iPhone & iPad', 'https://apps.apple.com/app/emby/id992180193'], ['Android', 'https://play.google.com/store/apps/details?id=com.mb.android']],
            self::Plex => [['iPhone & iPad', 'https://apps.apple.com/app/plex-watch-live-tv-and-movies/id383457673'], ['Android', 'https://play.google.com/store/apps/details?id=com.plexapp.android']],
            self::Silo => [],
        };
    }

    /** What the admin enters as the key. */
    public function keyLabel(): string
    {
        return match ($this) {
            self::Plex => __('Plex token of the server owner'),
            default => __('API key'),
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $type) => [$type->value => $type->label()])->all();
    }
}
