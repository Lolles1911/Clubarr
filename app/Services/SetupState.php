<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Whether the first admin exists, and the one-time setup token that guards
 * /setup until then (so a fresh Clubarr on the internet can't be claimed by
 * a stranger). The token is printed in the log at start.
 */
class SetupState
{
    private ?bool $done = null;

    public function isDone(): bool
    {
        if ($this->done === true) {
            return true;
        }
        try {
            return $this->done = User::where('is_admin', true)->exists();
        } catch (Throwable) {
            return false; // no database yet
        }
    }

    public function forget(): void
    {
        $this->done = null;
    }

    /** The token, made on first use. */
    public function token(): string
    {
        $path = (string) config('clubarr.setup_token_path');
        if (! File::exists($path)) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, bin2hex(random_bytes(16)));
            @chmod($path, 0600);
        }

        return trim((string) File::get($path));
    }

    public function check(string $given): bool
    {
        return hash_equals($this->token(), trim($given));
    }

    public function finish(): void
    {
        File::delete((string) config('clubarr.setup_token_path'));
        $this->done = true;
    }
}
