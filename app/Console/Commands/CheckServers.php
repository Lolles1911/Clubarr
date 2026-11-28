<?php

namespace App\Console\Commands;

use App\Models\MediaServer;
use App\Services\NewOnServers;
use App\Services\ServerSync;
use Illuminate\Console\Command;
use Throwable;

/** Every 5 minutes: does each media server answer? A change is logged (and can be an alert). Also refreshes "New on the server". */
class CheckServers extends Command
{
    protected $signature = 'clubarr:check-servers';

    protected $description = 'Check that the media servers answer';

    public function handle(ServerSync $sync, NewOnServers $new): int
    {
        foreach (MediaServer::where('is_enabled', true)->get() as $server) {
            try {
                $sync->check($server);
            } catch (Throwable) {
                continue; // noted on the server by check()
            }
            $new->warm($server); // "New on the server" for the members' page
        }

        return self::SUCCESS;
    }
}
