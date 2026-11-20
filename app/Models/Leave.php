<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An employee's leave. Its days are not counted as absent. */
class Leave extends Model
{
    use HasFactory, LogsActivity;

    public const TYPES = ['Annual', 'Sick', 'Emergency', 'Unpaid', 'Business trip', 'Other'];

    protected $fillable = ['employee_id', 'type', 'start_date', 'end_date', 'note', 'source', 'created_by'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function days(): int
    {
        return (int) $this->start_date->diffInDays($this->end_date) + 1;
    }

    public function activityLabel(): string
    {
        $who = $this->employee?->label() ?? '#'.$this->employee_id;

        return $who.': '.__($this->type).' ('.$this->start_date?->format('Y-m-d').' – '.$this->end_date?->format('Y-m-d').')';
    }
}
