<?php

namespace App\Services;

use App\Integrations\Discord;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Gives a linked member the Discord role while they have access, and takes it away after. */
class DiscordSync
{
    public function __construct(private ?Discord $discord = null)
    {
        $this->discord ??= Discord::fromSettings();
    }

    public function enabled(): bool
    {
        return $this->discord !== null;
    }

    public function sync(User $user): void
    {
        if ($this->discord === null || ! $this->discord->hasRole() || $user->discord_id === null) {
            return;
        }
        try {
            $user->hasActiveAccess() ? $this->discord->addRole($user->discord_id) : $this->discord->removeRole($user->discord_id);
        } catch (Throwable $e) {
            Log::info('Clubarr: Discord role for user '.$user->id.' not changed: '.$e->getMessage());
        }
    }

    /** A direct message, if the member linked Discord; never throws. */
    public function tell(User $user, string $text): void
    {
        if ($this->discord === null || $user->discord_id === null) {
            return;
        }
        try {
            $this->discord->sendDirectMessage($user->discord_id, $text);
        } catch (Throwable $e) {
            Log::info('Clubarr: Discord message to user '.$user->id.' not sent: '.$e->getMessage());
        }
    }
}
