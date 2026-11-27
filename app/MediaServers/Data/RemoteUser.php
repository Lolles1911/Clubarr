<?php

namespace App\MediaServers\Data;

/** A user on a media server. Server administrators are marked, and Clubarr never changes them. */
final readonly class RemoteUser
{
    public function __construct(
        public string $id,
        public string $username,
        public ?string $email = null,
        public bool $isAdmin = false,
        public bool $isDisabled = false,
    ) {}
}
