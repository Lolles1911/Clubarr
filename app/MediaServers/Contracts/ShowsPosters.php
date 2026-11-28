<?php

namespace App\MediaServers\Contracts;

/** Servers that hand out a poster for an item they named (see NewOnServers). */
interface ShowsPosters
{
    /** The poster of an item: [type, body], or null. */
    public function poster(string $ref): ?array;
}
