<?php

namespace App\Console\Commands;

use App\Services\Waitlist;
use Illuminate\Console\Command;

/** Every 10 minutes: invites the next in line when a seat is free. */
class RunWaitlist extends Command
{
    protected $signature = 'clubarr:waitlist';

    protected $description = 'Invite the next on the waiting list when there is room';

    public function handle(Waitlist $waitlist): int
    {
        $this->info('Invited '.$waitlist->tick().'.');

        return self::SUCCESS;
    }
}
