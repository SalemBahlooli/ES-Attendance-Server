<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Other working hours between two dates, e.g. Ramadan: for one shift, or for all
 * shifts when shift_id is empty. Grace and days off still come from the shift.
 */
class SpecialPeriod extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'start_date', 'end_date', 'start_time', 'end_time', 'shift_id'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function start(): string
    {
        return substr((string) $this->start_time, 0, 5);
    }

    public function end(): string
    {
        return substr((string) $this->end_time, 0, 5);
    }

    public function activityLabel(): string
    {
        return $this->name.' ('.$this->start_date?->format('Y-m-d').' – '.$this->end_date?->format('Y-m-d').')';
    }
}
