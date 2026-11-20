<?php

namespace App\Console\Commands;

use App\Support\Backups;
use Illuminate\Console\Command;

/** The scheduled backup: make one with the saved contents, then apply retention. */
class ScheduledBackup extends Command
{
    protected $signature = 'backups:scheduled';

    protected $description = 'Make a backup with the saved settings, then delete old ones by the retention rules';

    public function handle(): int
    {
        [$ok, $message] = Backups::run();
        $this->line($message);

        if ($ok) {
            $deleted = Backups::prune();
            $this->line(count($deleted).' old backup(s) deleted.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
