<?php

namespace App\Console\Commands;

use App\Services\UpdateCheck;
use Illuminate\Console\Command;

/** Daily: is a newer Clubarr out? */
class CheckForUpdate extends Command
{
    protected $signature = 'clubarr:check-update';

    protected $description = 'Look for a newer Clubarr release';

    public function handle(UpdateCheck $check): int
    {
        $new = $check->run();
        $this->info($new === null ? 'Up to date.' : 'Clubarr '.$new['version'].' is out.');

        return self::SUCCESS;
    }
}
