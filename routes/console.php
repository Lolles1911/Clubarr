<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('clubarr:check-access')->hourly()->withoutOverlapping();
Schedule::command('clubarr:stream-limits')->everyMinute()->withoutOverlapping();
Schedule::command('clubarr:waitlist')->everyTenMinutes()->withoutOverlapping();
Schedule::command('clubarr:check-servers')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('clubarr:sync-servers')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('clubarr:check-update')->dailyAt('04:17');
Schedule::command('clubarr:backup')->dailyAt('03:41');
Schedule::command('clubarr:invite-reminders')->hourly()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('auth:clear-resets')->hourly();
