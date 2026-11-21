<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeRequest;
use App\Models\Holiday;
use App\Models\Leave;

/**
 * Annual leave balance for one calendar year (the "Leave balances" feature).
 *
 * Entitlement = the employee's own number of days, else the default from Settings.
 * Only work days are taken from it: the shift's weekend days and public holidays are not.
 */
class LeaveBalance
{
    public const TYPE = 'Annual';

    /** @return array{year: int, entitlement: int, used: int, pending: int, remaining: int} */
    public static function for(Employee $employee, ?int $year = null): array
    {
        $year ??= (int) substr(Clock::today(), 0, 4);
        $from = "{$year}-01-01";
        $to = "{$year}-12-31";
        $entitlement = $employee->annual_leave_days ?? (int) setting('annual_leave_days', 30);

        $used = Leave::where('employee_id', $employee->id)->where('type', self::TYPE)
            ->where('end_date', '>=', $from)->where('start_date', '<=', $to)->get()
            ->sum(fn ($l) => self::workDays($employee, max($l->start_date->toDateString(), $from), min($l->end_date->toDateString(), $to)));

        $pending = EmployeeRequest::where('employee_id', $employee->id)->where('kind', 'leave')->where('status', 'pending')
            ->where('leave_type', self::TYPE)->where('end_date', '>=', $from)->where('start_date', '<=', $to)->get()
            ->sum(fn ($r) => self::workDays($employee, max($r->start_date->toDateString(), $from), min($r->end_date->toDateString(), $to)));

        return [
            'year' => $year, 'entitlement' => $entitlement, 'used' => (int) $used, 'pending' => (int) $pending,
            'remaining' => $entitlement - (int) $used,
        ];
    }

    /** Work days between two dates (both included): not the shift's weekend, not a public holiday. */
    public static function workDays(Employee $employee, string $start, string $end): int
    {
        if ($start > $end) {
            return 0;
        }
        $weekend = $employee->effectiveShift()?->weekendDays() ?? [];
        $holidays = Holiday::where('end_date', '>=', $start)->where('start_date', '<=', $end)->get()
            ->map(fn ($h) => [$h->start_date->toDateString(), $h->end_date->toDateString()])->all();

        $days = 0;
        $last = strtotime($end.' 12:00 UTC');
        for ($t = strtotime($start.' 12:00 UTC'); $t <= $last; $t += 86400) {
            $date = gmdate('Y-m-d', $t);
            if (in_array((int) gmdate('w', $t), $weekend, true)) {
                continue;
            }
            foreach ($holidays as [$hf, $ht]) {
                if ($hf <= $date && $date <= $ht) {
                    continue 2;
                }
            }
            $days++;
        }

        return $days;
    }
}
