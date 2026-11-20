<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Backups on the "backups" disk (storage/app/backups/app/*.zip), made with
 * spatie/laravel-backup. Policy (schedule, contents, retention) lives in
 * Settings under the backup_* keys; the Backups page edits it.
 */
class Backups
{
    public const DISK = 'backups';

    public const FOLDER = 'app';

    /** @return array<int, array{name:string, path:string, bytes:int, date:Carbon}> newest first */
    public static function files(): array
    {
        $disk = Storage::disk(self::DISK);

        return collect($disk->files(self::FOLDER))
            ->filter(fn ($path) => str_ends_with($path, '.zip'))
            ->map(fn ($path) => [
                'name' => basename($path),
                'path' => $path,
                'bytes' => $disk->size($path),
                'date' => Carbon::createFromTimestamp($disk->lastModified($path)),
            ])
            ->sortByDesc(fn ($f) => $f['date']->timestamp)
            ->values()
            ->all();
    }

    public static function path(string $name): ?string
    {
        $name = basename($name);
        $path = self::FOLDER.'/'.$name;

        return str_ends_with($name, '.zip') && Storage::disk(self::DISK)->exists($path) ? $path : null;
    }

    /**
     * Make a backup now. Returns [ok, message].
     *
     * @return array{0: bool, 1: string}
     */
    public static function run(?string $contents = null): array
    {
        $contents ??= setting('backup_contents', 'full');
        @set_time_limit(600);

        try {
            $code = Artisan::call('backup:run', array_filter([
                '--only-db' => $contents === 'db',
                '--disable-notifications' => true,
            ]));
            $output = trim(Artisan::output());
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }

        if ($code !== 0 || str_contains(strtolower($output), 'failed')) {
            return [false, self::lastLine($output)];
        }

        Activity::log('backup_created', null, $contents === 'db' ? __('Database only') : __('Database + uploaded files'));

        return [true, __('Backup created.')];
    }

    /**
     * Apply the retention rules: keep the newest N, delete anything older than D days.
     * The newest backup is never deleted.
     *
     * @return array<int, string> deleted file names
     */
    public static function prune(): array
    {
        $keepCount = (int) setting('backup_keep_count', 10);
        $keepDays = (int) setting('backup_keep_days', 30);
        $files = self::files();
        $deleted = [];

        foreach ($files as $i => $file) {
            if ($i === 0) {
                continue;
            }
            $tooMany = $keepCount > 0 && $i >= $keepCount;
            $tooOld = $keepDays > 0 && $file['date']->lt(now()->subDays($keepDays));
            if ($tooMany || $tooOld) {
                Storage::disk(self::DISK)->delete($file['path']);
                $deleted[] = $file['name'];
            }
        }

        return $deleted;
    }

    /** Cron expression for the schedule in Settings. */
    public static function cron(): string
    {
        [$h, $m] = array_map('intval', explode(':', (string) setting('backup_time', '03:00')) + [1 => 0]);

        return match (setting('backup_frequency', 'daily')) {
            'weekly' => sprintf('%d %d * * %d', $m, $h, (int) setting('backup_weekday', 5)),
            'monthly' => sprintf('%d %d %d * *', $m, $h, (int) setting('backup_month_day', 1)),
            default => sprintf('%d %d * * *', $m, $h),
        };
    }

    private static function lastLine(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));

        return $lines ? end($lines) : __('The backup failed. See storage/logs for details.');
    }
}
