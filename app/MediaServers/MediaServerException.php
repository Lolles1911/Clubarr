<?php

namespace App\MediaServers;

use RuntimeException;

/**
 * A media server said no, or could not be reached. The message is safe to
 * show an admin: it never holds a key or token.
 */
class MediaServerException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, public readonly bool $userTaken = false)
    {
        parent::__construct($message, $status ?? 0);
    }
}
