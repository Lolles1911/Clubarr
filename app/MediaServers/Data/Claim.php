<?php

namespace App\MediaServers\Data;

/** A one-time link where the member makes their own user on the server (Silo). */
final readonly class Claim
{
    public function __construct(public string $id, public string $url) {}
}
