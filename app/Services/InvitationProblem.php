<?php

namespace App\Services;

use RuntimeException;

/** The invitation can't be used (withdrawn, expired, used up, server full); the message is for the visitor. */
class InvitationProblem extends RuntimeException
{
    public function __construct(string $message, public readonly bool $full = false)
    {
        parent::__construct($message);
    }
}
