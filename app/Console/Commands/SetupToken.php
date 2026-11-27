<?php

namespace App\Console\Commands;

use App\Services\SetupState;
use Illuminate\Console\Command;

/** Prints the setup token while no admin exists (the container does this at start). */
class SetupToken extends Command
{
    protected $signature = 'clubarr:setup-token';

    protected $description = 'Show the one-time setup token, until the first admin exists';

    public function handle(SetupState $state): int
    {
        if ($state->isDone()) {
            $this->line('Clubarr is set up; there is no setup token any more.');

            return self::SUCCESS;
        }
        $this->line('');
        $this->line('  Open Clubarr in your browser and use this setup token:');
        $this->line('');
        $this->line('    '.$state->token());
        $this->line('');

        return self::SUCCESS;
    }
}
