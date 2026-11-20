<?php

namespace App\Support;

use App\Models\Group;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Reads and writes system settings.
 *
 * Definitions (type, default, rules) come from config/settings.php; the
 * `settings` table only holds values an admin changed. Everything is cached
 * as one array and the cache is dropped on every save, so reading a setting
 * costs nothing after the first request.
 *
 * Registered as a singleton; use the setting() helper for reads.
 */
class Settings
{
    private const CACHE_KEY = 'app.settings.v1';

    /** @var array<string, mixed>|null  key => stored (raw) value */
    private ?array $stored = null;

    /** Every field definition, flattened: key => definition (+ 'group'). */
    public function definitions(): array
    {
        $out = [];
        foreach (config('settings.groups', []) as $groupKey => $group) {
            foreach ($group['fields'] ?? [] as $key => $field) {
                $out[$key] = $field + ['group' => $groupKey];
            }
        }

        return $out;
    }

    public function definition(string $key): ?array
    {
        return $this->definitions()[$key] ?? null;
    }

    /** The visible settings pages: key => group config. */
    public function groups(): array
    {
        return array_filter(config('settings.groups', []), fn ($g) => empty($g['hidden']));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $definition = $this->definition($key);
        $stored = $this->stored();

        if (array_key_exists($key, $stored)) {
            return $this->cast($stored[$key], $definition) ?? $default;
        }

        // The default declared in config/settings.php wins; $default is the fallback
        // for keys that have none (or are not declared at all).
        return $definition['default'] ?? $default;
    }

    /** Every setting with its effective value. */
    public function all(): array
    {
        $out = [];
        foreach (array_keys($this->definitions()) as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    /**
     * Save values. Keys that are not declared in config/settings.php are refused,
     * so a typo can never create a setting nobody reads.
     *
     * @param  array<string, mixed>  $values
     */
    public function set(array $values): void
    {
        $now = now();
        foreach ($values as $key => $value) {
            $definition = $this->definition($key);
            if (! $definition) {
                throw new \InvalidArgumentException("Unknown setting [{$key}]. Declare it in config/settings.php first.");
            }

            $raw = $this->serialize($value, $definition);

            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $raw, 'updated_by' => auth()->id(), 'updated_at' => $now, 'created_at' => $now],
            );
        }

        $this->flush();
    }

    /** Drop a stored value so the default applies again. */
    public function forget(string $key): void
    {
        DB::table('settings')->where('key', $key)->delete();
        $this->flush();
    }

    public function flush(): void
    {
        $this->stored = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** Options for a select field: an array, or the name of a dynamic list. */
    public function options(array $field): array
    {
        $options = $field['options'] ?? [];
        if (is_array($options)) {
            return $options;
        }

        return match ($options) {
            'locales' => collect(config('settings.locales'))->mapWithKeys(fn ($l, $code) => [$code => $l['native']])->all(),
            'timezones' => collect(\DateTimeZone::listIdentifiers())->mapWithKeys(fn ($tz) => [$tz => str_replace('_', ' ', $tz)])->all(),
            'groups' => ['' => __('— None —')] + (Schema::hasTable('groups') ? Group::orderBy('name')->pluck('name', 'id')->all() : []),
            default => [],
        };
    }

    /** Validation rules for one field, built from its type plus its own rules. */
    public function rules(string $key): array
    {
        $field = $this->definition($key) ?? [];
        $rules = $field['rules'] ?? null;

        if ($rules !== null) {
            return $rules;
        }

        return match ($field['type'] ?? 'text') {
            'email' => ['nullable', 'email', 'max:255'],
            'url' => ['nullable', 'url', 'max:255'],
            'number' => ['nullable', 'integer'],
            'toggle' => ['boolean'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'select' => ['nullable', 'in:'.implode(',', array_map('strval', array_keys($this->options($field))))],
            'image' => ['nullable', 'file', 'mimes:png,jpg,jpeg,svg,webp,ico', 'max:2048'],
            'textarea' => ['nullable', 'string', 'max:5000'],
            default => ['nullable', 'string', 'max:255'],
        };
    }

    // ------------------------------------------------------------------

    private function stored(): array
    {
        if ($this->stored !== null) {
            return $this->stored;
        }

        try {
            return $this->stored = Cache::rememberForever(self::CACHE_KEY, function () {
                if (! Schema::hasTable('settings')) {
                    return [];
                }

                return DB::table('settings')->pluck('value', 'key')->all();
            });
        } catch (Throwable) {
            // No database yet (fresh install, `composer install` running artisan): use the defaults.
            return $this->stored = [];
        }
    }

    private function cast(mixed $raw, ?array $definition): mixed
    {
        if ($raw === null) {
            return null;
        }

        if (! empty($definition['encrypted'])) {
            try {
                $raw = Crypt::decryptString($raw);
            } catch (Throwable) {
                return null; // APP_KEY changed: the secret has to be entered again
            }
        }

        return match ($definition['type'] ?? 'text') {
            'toggle' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'number' => (int) $raw,
            default => $raw,
        };
    }

    private function serialize(mixed $value, array $definition): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = match ($definition['type'] ?? 'text') {
            'toggle' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            default => is_array($value) ? json_encode($value) : (string) $value,
        };

        return ! empty($definition['encrypted']) && $value !== '' ? Crypt::encryptString($value) : $value;
    }

    /** Only for tests: pretend these values are stored. */
    public function fake(array $values): void
    {
        $this->stored = array_merge($this->stored(), Arr::map($values, fn ($v) => is_bool($v) ? ($v ? '1' : '0') : $v));
    }
}
