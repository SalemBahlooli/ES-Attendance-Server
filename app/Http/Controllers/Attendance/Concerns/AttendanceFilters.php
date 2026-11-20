<?php

namespace App\Http\Controllers\Attendance\Concerns;

use App\Models\Department;
use App\Services\Attendance\Clock;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Shared bits of the attendance pages: dates, periods and the department filter. */
trait AttendanceFilters
{
    protected function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false;
    }

    /**
     * A period of at most $maxDays days from the request (from / to), or a default.
     *
     * @return array{0: string, 1: string}
     */
    protected function period(Request $request, ?string $defaultFrom = null, ?string $defaultTo = null, int $maxDays = 366): array
    {
        $from = $request->query('from', $defaultFrom ?? substr(Clock::today(), 0, 8).'01');
        $to = $request->query('to', $defaultTo ?? Clock::today());
        if (! $this->isDate($from) || ! $this->isDate($to) || $from > $to) {
            throw ValidationException::withMessages(['from' => __('Pick a valid period.')]);
        }
        if ((strtotime($to) - strtotime($from)) / 86400 > $maxDays) {
            throw ValidationException::withMessages(['from' => __('Pick a period of one year or less.')]);
        }

        return [$from, $to];
    }

    protected function departmentId(Request $request): ?int
    {
        $id = (int) $request->query('department_id');

        return $id > 0 ? $id : null;
    }

    /** @return array<int, string> */
    protected function departmentOptions(): array
    {
        return Department::orderBy('name')->pluck('name', 'id')->all();
    }

    protected function addDays(string $date, int $days): string
    {
        return gmdate('Y-m-d', strtotime($date.' 12:00 UTC') + $days * 86400);
    }
}
