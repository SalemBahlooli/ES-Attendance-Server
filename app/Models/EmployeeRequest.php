<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something an employee asked for from the portal: a leave (kind = leave) or a
 * punch they forgot (kind = punch). Someone with requests.review approves or rejects it.
 */
class EmployeeRequest extends Model
{
    use LogsActivity;

    public const KINDS = ['leave' => 'Leave', 'punch' => 'Punch correction'];

    public const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

    public const STATUS_COLORS = ['pending' => 'amber', 'approved' => 'green', 'rejected' => 'red', 'cancelled' => 'gray'];

    protected $fillable = ['employee_id', 'user_id', 'kind', 'leave_type', 'start_date', 'end_date', 'punch_time', 'reason'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'reviewed_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Only the kinds switched on in Settings. */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->whereIn('kind', self::enabledKinds());
    }

    /** @return array<int, string> */
    public static function enabledKinds(): array
    {
        return array_values(array_filter([feature('leave_requests') ? 'leave' : null, feature('punch_requests') ? 'punch' : null]));
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** "Annual leave 2026-11-01 – 2026-11-05" or "Punch 2026-11-01 16:02" */
    public function summary(): string
    {
        return $this->kind === 'leave'
            ? __($this->leave_type).': '.format_day($this->start_date?->toDateString()).($this->end_date && ! $this->end_date->equalTo($this->start_date) ? ' – '.format_day($this->end_date->toDateString()) : '')
            : __('Punch at :time', ['time' => format_day(substr((string) $this->punch_time, 0, 10)).' '.substr((string) $this->punch_time, 11, 5)]);
    }

    public function activityLabel(): string
    {
        return ($this->employee?->label() ?? '#'.$this->employee_id).': '.$this->summary();
    }
}
