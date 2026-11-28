<?php

namespace App\Console\Commands;

use App\Models\MediaServer;
use App\Services\ServerSync;
use Illuminate\Console\Command;
use Throwable;

/** Every 5 minutes: does each media server answer? A change is logged (and can be an alert). */
class CheckServers extends Command
{
    protected $signature = 'clubarr:check-servers';

    protected $description = 'Check that the media servers answer';

    public function handle(ServerSync $sync): int
    {
        foreach (MediaServer::where('is_enabled', true)->get() as $server) {
            try {
                $sync->check($server);
            } catch (Throwable) {
                // noted on the server by check()
            }
        }

        return self::SUCCESS;
    }
}
