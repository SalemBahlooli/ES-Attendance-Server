<?php

use App\Support\Backups;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| The Docker image runs `php artisan schedule:work` for you. On a normal
| server add one cron line:   * * * * * cd /path && php artisan schedule:run
| See what is scheduled with: php artisan schedule:list
*/

// Data retention (Settings → Data retention)
Schedule::command('model:prune')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('notifications:prune')->dailyAt('02:40')->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('queue:prune-failed --hours=720')->weekly();

// Backups (Settings → Backups)
Schedule::command('backups:scheduled')
    ->cron(Backups::cron())
    ->when(fn () => (bool) setting('backup_enabled'))
    ->timezone(setting('timezone', 'UTC'))
    ->withoutOverlapping()
    ->runInBackground();
