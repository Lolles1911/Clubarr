<?php

namespace App\MediaServers\Data;

/** A plex.tv account, as Plex told us after the member signed in with Plex. */
final readonly class PlexIdentity
{
    public function __construct(public string $id, public string $username, public ?string $email) {}
}
