<?php

/*
|--------------------------------------------------------------------------
| Global helpers (autoloaded through composer.json "files")
|--------------------------------------------------------------------------
*/

use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

if (! function_exists('setting')) {
    /** Read a system setting declared in config/settings.php. */
    function setting(string $key, mixed $default = null): mixed
    {
        return app(Settings::class)->get($key, $default);
    }
}

if (! function_exists('display_timezone')) {
    /** The time zone dates are shown in: the person's own, else the system's. */
    function display_timezone(): string
    {
        $tz = auth()->user()?->timezone ?: setting('timezone', 'UTC');

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC';
    }
}

if (! function_exists('format_date')) {
    /** A date in the system's date format and the viewer's time zone. */
    function format_date(CarbonInterface|string|null $value): string
    {
        if (! $value) {
            return '';
        }
        $date = $value instanceof CarbonInterface ? $value : Carbon::parse($value);

        return $date->copy()->setTimezone(display_timezone())->translatedFormat(setting('date_format', 'Y-m-d'));
    }
}

if (! function_exists('format_datetime')) {
    /** A date and time in the system's formats and the viewer's time zone. */
    function format_datetime(CarbonInterface|string|null $value): string
    {
        if (! $value) {
            return '';
        }
        $date = $value instanceof CarbonInterface ? $value : Carbon::parse($value);

        return $date->copy()->setTimezone(display_timezone())
            ->translatedFormat(setting('date_format', 'Y-m-d').' '.setting('time_format', 'H:i'));
    }
}

if (! function_exists('format_day')) {
    /** A calendar date ("2026-11-20") in the system's date format, never shifted by a time zone. */
    function format_day(?string $date): string
    {
        return $date ? Carbon::parse(substr($date, 0, 10), 'UTC')->translatedFormat(setting('date_format', 'Y-m-d')) : '';
    }
}

if (! function_exists('format_bytes')) {
    function format_bytes(int|float|null $bytes, int $precision = 1): string
    {
        $bytes = max(0, (float) $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

        return round($bytes / (1024 ** $i), $precision).' '.$units[$i];
    }
}

if (! function_exists('is_rtl')) {
    function is_rtl(): bool
    {
        return (config('settings.locales.'.app()->getLocale().'.dir') ?? 'ltr') === 'rtl';
    }
}
