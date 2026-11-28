<?php

namespace App\Console\Commands;

use App\Services\Watching;
use Illuminate\Console\Command;
use Throwable;

/** Every minute: stops streams over members' limits (Tracearr). */
class EnforceStreamLimits extends Command
{
    protected $signature = 'clubarr:stream-limits';

    protected $description = 'Stop streams over each member\'s limit (needs Tracearr)';

    public function handle(Watching $watching): int
    {
        try {
            $this->info('Stopped '.$watching->enforceLimits().' streams.');
        } catch (Throwable $e) {
            $this->warn('Tracearr: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
