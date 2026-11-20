<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Someone who punches on the device. `pin` is the employee number on the device;
 * employees are created automatically the first time a punch arrives for a new number.
 */
class Employee extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = ['pin', 'name', 'department_id', 'shift_id', 'is_active', 'start_date'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'start_date' => 'date:Y-m-d',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(Leave::class);
    }

    public function punches(): HasMany
    {
        return $this->hasMany(Punch::class, 'pin', 'pin');
    }

    /** Numbers in numeric order (2 before 10), then anything else. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByRaw('LENGTH(pin)')->orderBy('pin');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        return $term === '' ? $query : $query->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('pin', 'like', "%{$term}%"));
    }

    /** The shift that applies: own, else the department's, else the default one. */
    public function effectiveShift(): ?Shift
    {
        return $this->shift ?? $this->department?->shift ?? Shift::default();
    }

    public function activityLabel(): string
    {
        return $this->label();
    }

    /** "Name (12)" or "Employee 12" */
    public function label(): string
    {
        return $this->name ? "{$this->name} ({$this->pin})" : __('Employee :pin', ['pin' => $this->pin]);
    }

    public static function labelFor(string $pin): string
    {
        return static::where('pin', $pin)->first()?->label() ?? __('Employee :pin', ['pin' => $pin]);
    }

    /** Make sure an employee row exists for each of these numbers. */
    public static function ensure(array $pins): void
    {
        $pins = array_values(array_unique(array_filter(array_map('strval', $pins), fn ($p) => $p !== '')));
        $now = now();
        foreach (array_chunk($pins, 500) as $chunk) {
            static::insertOrIgnore(array_map(fn ($pin) => ['pin' => $pin, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now], $chunk));
        }
    }
}
