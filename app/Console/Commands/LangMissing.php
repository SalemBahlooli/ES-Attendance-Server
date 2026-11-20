<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\ChangelogEntry;
use App\Models\SignIn;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Find English text that has no translation yet.
 *
 *   php artisan lang:missing ar           # list what lang/ar.json is missing
 *   php artisan lang:missing ar --write   # add the missing keys to lang/ar.json (value = English, to translate)
 *
 * Scans __('...'), trans_choice('...') and @lang('...') in app/, resources/views
 * and routes/, plus the labels in config/menu.php, config/settings.php and
 * config/permission_tree.php.
 */
class LangMissing extends Command
{
    protected $signature = 'lang:missing {locale=ar} {--write : Add the missing keys to the JSON file}';

    protected $description = 'List (or add) translation keys missing from lang/<locale>.json';

    public function handle(): int
    {
        $locale = $this->argument('locale');
        $path = lang_path($locale.'.json');
        $existing = File::exists($path) ? json_decode(File::get($path), true) ?? [] : [];

        $keys = $this->keys();
        $missing = array_values(array_diff($keys, array_keys($existing)));

        if (! $missing) {
            $this->info("lang/{$locale}.json has every key (".count($keys).').');

            return self::SUCCESS;
        }

        foreach ($missing as $key) {
            $this->line($key);
        }
        $this->warn(count($missing).' missing of '.count($keys).'.');

        if ($this->option('write')) {
            foreach ($missing as $key) {
                $existing[$key] = $key;
            }
            ksort($existing, SORT_NATURAL | SORT_FLAG_CASE);
            File::put($path, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            $this->info("Added to lang/{$locale}.json — translate the English values.");

            return self::SUCCESS;
        }

        return self::FAILURE; // lets CI stop a change with untranslated text
    }

    /** @return array<int, string> */
    private function keys(): array
    {
        $keys = [];
        $files = array_merge(File::allFiles(app_path()), File::allFiles(resource_path('views')), File::allFiles(base_path('routes')));

        foreach ($files as $file) {
            $text = $file->getContents();
            preg_match_all("/(?:__|trans_choice|@lang)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $text, $single);
            preg_match_all('/(?:__|trans_choice|@lang)\(\s*"((?:[^"\\\\]|\\\\.)*)"/', $text, $double);
            foreach (array_merge($single[1], $double[1]) as $key) {
                $keys[] = stripslashes($key);
            }
        }

        // Labels from config files are passed through __() at render time.
        $walk = function ($node) use (&$walk, &$keys) {
            if (! is_array($node)) {
                return;
            }
            foreach (['label', 'heading', 'description', 'note', 'help'] as $field) {
                if (isset($node[$field]) && is_string($node[$field]) && $node[$field] !== '') {
                    $keys[] = $node[$field];
                }
            }
            foreach ($node as $key => $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk(config('menu'));
        $walk(config('permission_tree'));
        $walk(config('settings.groups'));

        foreach (array_keys(config('permission_tree')) as $area) {
            foreach (array_keys(config("permission_tree.{$area}.sections", [])) as $section) {
                $keys[] = $section;
            }
        }
        foreach (config('settings.groups') as $group) {
            foreach ($group['fields'] ?? [] as $field) {
                if (is_array($field['options'] ?? null)) {
                    foreach ($field['options'] as $label) {
                        $keys[] = (string) $label;
                    }
                }
            }
        }

        // Model constants used as labels
        foreach ([ActivityLog::ACTIONS, Announcement::TYPES, ChangelogEntry::CATEGORIES] as $list) {
            foreach ($list as $row) {
                $keys[] = $row[0];
            }
        }
        foreach ([SignIn::METHODS, SignIn::REASONS] as $list) {
            foreach ($list as $label) {
                $keys[] = $label;
            }
        }
        foreach (['Active now', 'Idle', 'Seen today', 'Last 7 days', 'Not seen for 30+ days', 'Never signed in'] as $label) {
            $keys[] = $label;
        }

        // Skip keys of PHP language files ("passwords.reset"): those live in lang/<locale>/*.php.
        $keys = array_filter(array_unique($keys), fn ($k) => $k !== '' && ! str_contains($k, '{$') && ! preg_match('/^[a-z0-9_-]+(\.[a-z0-9_-]+)+$/', $k));
        sort($keys, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($keys);
    }
}
