<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Delete read notifications older than Settings → Data retention. */
class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune';

    protected $description = 'Delete read notifications older than the retention setting';

    public function handle(): int
    {
        $days = (int) setting('notification_retention_days', 90);
        if ($days <= 0) {
            $this->line('Retention is off (0 days): nothing deleted.');

            return self::SUCCESS;
        }

        $count = DB::table('notifications')
            ->whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays($days))
            ->delete();

        $this->line($count.' notification(s) deleted.');

        return self::SUCCESS;
    }
}
