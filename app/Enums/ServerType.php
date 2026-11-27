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
