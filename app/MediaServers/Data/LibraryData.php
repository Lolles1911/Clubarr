<?php

namespace App\MediaServers\Data;

final readonly class LibraryData
{
    public function __construct(public string $id, public string $name, public ?string $kind = null) {}
}
