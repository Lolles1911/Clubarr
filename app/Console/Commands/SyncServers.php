<?php

namespace App\Console\Commands;

use App\MediaServers\MediaServerException;
use App\Models\MediaServer;
use App\Services\ServerSync;
use Illuminate\Console\Command;
use Throwable;

/**
 * Every 15 minutes: what "Sync" on a server does, for every server in use:
 * libraries, members' users (paused/active, deleted on the server) and the
 * count of users not in Clubarr. A server that doesn't answer is skipped.
 */
class SyncServers extends Command
{
    protected $signature = 'clubarr:sync-servers';

    protected $description = 'Bring Clubarr up to date with the media servers';

    public function handle(ServerSync $sync): int
    {
        foreach (MediaServer::where('is_enabled', true)->get() as $server) {
            try {
                $sync->check($server);
                $sync->syncLibraries($server);
                $users = $sync->syncUsers($server);
                $this->info($server->name.': '.json_encode($users));
            } catch (MediaServerException $e) {
                $this->warn($server->name.': '.$e->getMessage());
            } catch (Throwable $e) {
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
