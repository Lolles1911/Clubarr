<?php

namespace App\MediaServers\Contracts;

/** Servers where Clubarr can change which libraries a user sees, after joining. */
interface ChangesLibraries
{
    /** @param array<int, string>|null $libraryIds null = all libraries */
    public function setLibraries(string $remoteId, ?array $libraryIds): void;
}
