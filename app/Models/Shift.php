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

    protected $fillable = ['name', 'start_time', 'end_time', 'grace_in', 'grace_out', 'weekend', 'is_default'];

    protected function casts(): array
    {
        return [
            'weekend' => 'array',
            'is_default' => 'boolean',
            'grace_in' => 'integer',
            'grace_out' => 'integer',
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
