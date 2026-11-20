<?php

namespace App\Support;

/**
 * A tiny, dependency-free user-agent reader. We only need a human label
 * ("Edge on Windows") and a stable family key to tell devices apart, not
 * exact versions: those change every few weeks and would make every browser
 * update look like a brand-new device.
 */
class UserAgent
{
    /** @return array{browser:string, os:string, type:string, label:string, family:string} */
    public static function parse(?string $ua): array
    {
        $ua = (string) $ua;

        $browser = match (true) {
            $ua === '' || $ua === 'Symfony' => 'Unknown',
            (bool) preg_match('/Edg(e|A|iOS)?\//', $ua) => 'Edge',
            (bool) preg_match('/OPR\/|Opera/', $ua) => 'Opera',
            (bool) preg_match('/SamsungBrowser/', $ua) => 'Samsung Internet',
            (bool) preg_match('/Firefox|FxiOS/', $ua) => 'Firefox',
            (bool) preg_match('/CriOS|Chrome|Chromium/', $ua) => 'Chrome',
            (bool) preg_match('/Safari/', $ua) => 'Safari',
            (bool) preg_match('/curl|wget|python|Go-http|okhttp|PostmanRuntime/i', $ua) => 'API client',
            default => 'Browser',
        };

        $os = match (true) {
            (bool) preg_match('/Windows/', $ua) => 'Windows',
            (bool) preg_match('/iPhone|iPad|iPod/', $ua) => 'iOS',
            (bool) preg_match('/Android/', $ua) => 'Android',
            (bool) preg_match('/Mac OS X|Macintosh/', $ua) => 'macOS',
            (bool) preg_match('/CrOS/', $ua) => 'ChromeOS',
            (bool) preg_match('/Linux/', $ua) => 'Linux',
            default => $ua === '' ? 'Unknown' : 'Other',
        };

        $type = match (true) {
            (bool) preg_match('/iPad|Tablet|(Android(?!.*Mobile))/', $ua) => 'tablet',
            (bool) preg_match('/Mobi|iPhone|Android/', $ua) => 'mobile',
            $browser === 'API client' || $browser === 'Unknown' => 'other',
            default => 'desktop',
        };

        return [
            'browser' => $browser,
            'os' => $os,
            'type' => $type,
            'label' => $browser === 'Unknown' ? __('Unknown device') : __(':browser on :os', ['browser' => $browser, 'os' => $os]),
            'family' => strtolower($browser.'|'.$os.'|'.$type),
        ];
    }

    /** Font Awesome icon for the device type. */
    public static function icon(string $type): string
    {
        return match ($type) {
            'mobile' => 'fa-mobile-screen',
            'tablet' => 'fa-tablet-screen-button',
            'other' => 'fa-terminal',
            default => 'fa-desktop',
        };
    }
}
