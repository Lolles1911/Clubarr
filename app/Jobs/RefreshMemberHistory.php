<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\MemberHistory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Fetches one member's watch history from their Jellyfin/Emby users, on the queue, so their page never waits for the servers. */
class RefreshMemberHistory implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $userId) {}

    public function handle(MemberHistory $history): void
    {
        $user = User::find($this->userId);
        if ($user !== null) {
            $history->refresh($user);
        }
    }
}
