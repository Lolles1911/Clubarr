<?php

namespace App\MediaServers;

use App\MediaServers\Contracts\MediaServerDriver;
use App\Models\MediaServer;

/** Makes the driver for a media server. Bound in the container, so tests can swap it. */
class DriverFactory
{
    public function for(MediaServer $server): MediaServerDriver
    {
        $class = $server->type->driver();

        return new $class($server);
    }
}
