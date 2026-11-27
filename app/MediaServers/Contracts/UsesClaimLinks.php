<?php

namespace App\MediaServers\Contracts;

use App\MediaServers\Data\Claim;
use App\MediaServers\Data\RemoteUser;

/** Silo: the member makes their user themselves, on a one-time link. */
interface UsesClaimLinks
{
    /** @param array<int, string>|null $libraryIds null = Silo's default */
    public function createClaim(?array $libraryIds): Claim;

    /** The user made on the link, or null while it is unused. */
    public function claimedUser(string $claimId): ?RemoteUser;

    /** A link where the member chooses a new password. */
    public function passwordResetLink(string $remoteId): string;
}
