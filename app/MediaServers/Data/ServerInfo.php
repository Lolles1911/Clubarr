<?php

namespace App\MediaServers\Data;

final readonly class ServerInfo
{
    public function __construct(public string $name, public ?string $version = null, public ?string $id = null) {}
}
