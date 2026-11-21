<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Another shift for one employee between two dates (shift schedules).
 * When two overlap, the one added last wins.
 */
class ShiftAssignment extends Model
{
    use LogsActivity;

    protected $fillable = ['employee_id', 'shift_id', 'start_date', 'end_date', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function activityLabel(): string
    {
        return ($this->employee?->label() ?? '#'.$this->employee_id).': '.($this->shift?->name ?? '').' ('.$this->start_date?->format('Y-m-d').' – '.$this->end_date?->format('Y-m-d').')';
    }
}
