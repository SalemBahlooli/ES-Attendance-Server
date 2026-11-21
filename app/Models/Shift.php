<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A work schedule: start and end time, late / early-leave grace and the weekly days off.
 * Which shift applies to an employee: their own, else their department's, else the default.
 */
class Shift extends Model
{
    use HasFactory, LogsActivity;

    public const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    protected $fillable = ['name', 'start_time', 'end_time', 'grace_in', 'grace_out', 'weekend', 'is_default', 'flexible', 'required_minutes'];

    protected function casts(): array
    {
        return [
            'weekend' => 'array',
            'is_default' => 'boolean',
            'grace_in' => 'integer',
            'grace_out' => 'integer',
            'flexible' => 'boolean',
            'required_minutes' => 'integer',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public static function default(): ?self
    {
        return static::orderByDesc('is_default')->orderBy('id')->first();
    }

    /** "08:00" */
    public function start(): string
    {
        return substr((string) $this->start_time, 0, 5);
    }

    public function end(): string
    {
        return substr((string) $this->end_time, 0, 5);
    }

    /** Flexible hours apply: the shift is marked flexible and the feature is on. */
    public function isFlexible(): bool
    {
        return $this->flexible && feature('flexible');
    }

    /** Minutes from start to end (a night shift crosses midnight). */
    public function lengthMinutes(): int
    {
        $m = fn (string $t) => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);

        return (($m($this->end()) - $m($this->start()) + 1440) % 1440) ?: 1440;
    }

    /** Minutes to work per day on a flexible shift. */
    public function requiredMinutes(): int
    {
        return $this->required_minutes ?: $this->lengthMinutes();
    }

    /** @return array<int, int> */
    public function weekendDays(): array
    {
        return array_values(array_map('intval', $this->weekend ?? []));
    }

    public function weekendLabel(): string
    {
        return collect($this->weekendDays())->sort()->map(fn ($d) => __(self::DAY_NAMES[$d] ?? ''))->implode(', ') ?: __('None');
    }
}
