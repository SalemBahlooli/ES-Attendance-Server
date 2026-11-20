<?php

namespace App\Services\Attendance;

use Illuminate\Support\Carbon;

/**
 * The devices' clock. Devices record naive local times, in the system time zone
 * (Settings → General → Time zone). All attendance maths uses these local times.
 */
class Clock
{
    public static function timezone(): string
    {
        $tz = (string) setting('timezone', 'Asia/Riyadh');

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC';
    }

    public static function now(): Carbon
    {
        return now(self::timezone());
    }

    /** "2026-11-20 08:01:22" in device local time */
    public static function nowStamp(): string
    {
        return self::now()->format('Y-m-d H:i:s');
    }

    public static function today(): string
    {
        return self::now()->toDateString();
    }

    /** Hours from UTC, for the device handshake (TimeZone=3). */
    public static function offsetHours(): int|float
    {
        $seconds = self::now()->getOffset();

        return $seconds % 3600 === 0 ? intdiv($seconds, 3600) : round($seconds / 3600, 1);
    }

    /** A naive "Y-m-d H:i:s" as seconds, treating it as UTC so nothing shifts. */
    public static function seconds(string $stamp): int
    {
        return (int) strtotime(substr($stamp, 0, 19).' UTC');
    }

    public static function stamp(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', $seconds);
    }
}
