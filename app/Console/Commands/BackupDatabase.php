<?php

namespace App\Console\Commands;

use App\Services\Backups;
use Illuminate\Console\Command;

/** Nightly: a copy of the database in /config/backups (the newest 7 are kept). */
class BackupDatabase extends Command
{
    protected $signature = 'clubarr:backup';

    protected $description = 'Copy the database to the backups folder';

    public function handle(Backups $backups): int
    {
        if (! Backups::supported()) {
            $this->warn('Not SQLite: back up the database with its own tools.');

            return self::SUCCESS;
        }
        $this->info('Saved '.$backups->automatic().'.');

        return self::SUCCESS;
    }
}
