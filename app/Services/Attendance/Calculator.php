<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\Punch;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * Attendance for every active employee, day by day.
 *
 * Rules:
 *  - The shift that applies: the employee's own, else the department's, else the default.
 *  - A shift's day starts 4 hours before the shift start and lasts 24 hours, so night
 *    shifts are counted on the day they start.
 *  - The first punch is the check-in, the last is the check-out. Two punches less than
 *    5 minutes apart are one punch (a double tap).
 *  - Late = first punch later than start + grace (counted in full minutes).
 *    Early leave = last punch earlier than end - grace.
 *  - Public holidays and weekend days are days off; punching on one is "worked day off".
 *  - Without a start date, days before the employee's first punch are not counted.
 */
class Calculator
{
    public const STATUSES = [
        'present' => 'Present',
        'late' => 'Late',
        'absent' => 'Absent',
        'leave' => 'On leave',
        'holiday' => 'Public holiday',
        'weekend' => 'Weekend',
        'offday_work' => 'Worked day off',
        'not_yet' => 'Not in yet',
    ];

    public const STATUS_COLORS = [
        'present' => 'green', 'late' => 'amber', 'absent' => 'red', 'leave' => 'blue',
        'holiday' => 'gray', 'weekend' => 'gray', 'offday_work' => 'teal', 'not_yet' => 'gray',
    ];

    private const MIN = 60;

    private const DAY = 86400;

    /** The shift's day starts this long before the shift start. */
    private const WINDOW_BEFORE = 4 * 3600;

    /** Two punches closer than this count as one. */
    private const MIN_GAP = 5 * 60;

    /**
     * @param  array<int, string>|null  $pins  only these employees
     * @return array{days: array<int, array<string, mixed>>, summary: array<int, array<string, mixed>>}
     */
    public function compute(string $from, string $to, ?int $departmentId = null, ?array $pins = null): array
    {
        $shifts = Shift::orderBy('id')->get()->keyBy('id');
        $defaultShift = $shifts->firstWhere('is_default', true) ?? $shifts->first();
        if (! $defaultShift) {
            return ['days' => [], 'summary' => []];
        }

        $employees = Employee::query()
            ->with('department:id,name,shift_id')
            ->where('is_active', true)
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->when($pins !== null, fn ($q) => $q->whereIn('pin', $pins))
            ->ordered()
            ->get();
        if ($employees->isEmpty()) {
            return ['days' => [], 'summary' => []];
        }
        $employeePins = $employees->pluck('pin')->all();

        $firstPunch = Punch::query()
            ->whereIn('pin', $employeePins)
            ->groupBy('pin')
            ->select('pin', DB::raw('MIN(punch_time) AS first'))
            ->pluck('first', 'pin');

        $holidays = Holiday::where('end_date', '>=', $from)->where('start_date', '<=', $to)->get()
            ->map(fn ($h) => ['name' => $h->name, 'from' => $h->start_date->toDateString(), 'to' => $h->end_date->toDateString()]);

        $leaves = Leave::query()->with('employee:id,pin')
            ->where('end_date', '>=', $from)->where('start_date', '<=', $to)
            ->whereIn('employee_id', $employees->pluck('id'))
            ->get()
            ->groupBy(fn ($l) => $l->employee->pin)
            ->map(fn ($list) => $list->map(fn ($l) => [
                'type' => $l->type, 'note' => $l->note,
                'from' => $l->start_date->toDateString(), 'to' => $l->end_date->toDateString(),
            ])->all());

        $punchesByPin = [];
        $manual = [];
        Punch::query()
            ->whereIn('pin', $employeePins)
            ->where('punch_time', '>=', $this->addDays($from, -1).' 00:00:00')
            ->where('punch_time', '<', $this->addDays($to, 2).' 00:00:00')
            ->orderBy('punch_time')
            ->toBase()
            ->select('pin', 'punch_time', 'source')
            ->lazy(5000)
            ->each(function ($p) use (&$punchesByPin, &$manual) {
                $t = Clock::seconds((string) $p->punch_time);
                $punchesByPin[$p->pin][] = $t;
                if ($p->source === 'manual') {
                    $manual[$p->pin.'|'.$t] = true;
                }
            });

        $now = Clock::seconds(Clock::nowStamp());
        $lastDay = min(Clock::seconds($to.' 00:00:00'), Clock::seconds(Clock::today().' 00:00:00'));
        $firstDay = Clock::seconds($from.' 00:00:00');

        $days = [];
        $summary = [];

        foreach ($employees as $emp) {
            $shift = $shifts->get($emp->shift_id) ?? $shifts->get($emp->department?->shift_id) ?? $defaultShift;
            $punches = $punchesByPin[$emp->pin] ?? [];
            $startDate = $emp->start_date?->toDateString() ?? ($firstPunch[$emp->pin] ?? null ? substr((string) $firstPunch[$emp->pin], 0, 10) : null);
            $weekend = $shift->weekendDays();
            $startMin = $this->minutes($shift->start_time);
            $lengthMin = (($this->minutes($shift->end_time) - $startMin + 1440) % 1440) ?: 1440;
            $empLeaves = $leaves[$emp->pin] ?? [];

            $sum = [
                'employee_id' => $emp->id, 'pin' => $emp->pin, 'name' => $emp->name, 'department_id' => $emp->department_id, 'department' => $emp->department?->name,
                'shift' => $shift->name, 'work_days' => 0, 'present' => 0, 'absent' => 0, 'leave' => 0, 'late_count' => 0, 'late_minutes' => 0,
                'early_count' => 0, 'early_minutes' => 0, 'missing_out' => 0, 'offday_work' => 0, 'worked_minutes' => 0,
            ];

            $i = 0;
            $n = count($punches);
            for ($d = $firstDay; $d <= $lastDay; $d += self::DAY) {
                $date = gmdate('Y-m-d', $d);
                $start = $d + $startMin * self::MIN;
                $end = $start + $lengthMin * self::MIN;
                $winStart = $start - self::WINDOW_BEFORE;
                $winEnd = $winStart + self::DAY;

                while ($i < $n && $punches[$i] < $winStart) {
                    $i++;
                }
                $ps = [];
                for ($j = $i; $j < $n && $punches[$j] < $winEnd; $j++) {
                    $ps[] = $punches[$j];
                }

                if ($startDate && $date < $startDate) {
                    continue;
                }

                $holiday = collect($holidays)->first(fn ($h) => $h['from'] <= $date && $date <= $h['to']);
                $leave = collect($empLeaves)->first(fn ($l) => $l['from'] <= $date && $date <= $l['to']);
                $dow = (int) gmdate('w', $d);
                $off = $holiday ? 'holiday' : (in_array($dow, $weekend, true) ? 'weekend' : null);

                $row = [
                    'date' => $date, 'day_name' => Shift::DAY_NAMES[$dow], 'employee_id' => $emp->id, 'pin' => $emp->pin, 'name' => $emp->name,
                    'department_id' => $emp->department_id, 'department' => $emp->department?->name,
                    'shift' => $shift->name, 'shift_start' => $shift->start(), 'shift_end' => $shift->end(),
                    'first_in' => null, 'last_out' => null, 'punches' => count($ps),
                    'times' => array_map(fn ($p) => gmdate('H:i', $p), $ps),
                    'manual_times' => array_values(array_map(fn ($p) => gmdate('H:i', $p), array_filter($ps, fn ($p) => isset($manual[$emp->pin.'|'.$p])))),
                    'worked_minutes' => 0, 'late_minutes' => 0, 'early_minutes' => 0, 'missing_out' => false,
                    'status' => null, 'leave_type' => null, 'note' => null,
                ];

                if ($ps) {
                    $first = $ps[0];
                    $last = $ps[count($ps) - 1];
                    $hasOut = $last - $first >= self::MIN_GAP;
                    $row['first_in'] = Clock::stamp($first);
                    if ($hasOut) {
                        $row['last_out'] = Clock::stamp($last);
                        $row['worked_minutes'] = intdiv($last - $first, self::MIN);
                    }
                    if ($off) {
                        $row['status'] = 'offday_work';
                        $row['note'] = $holiday['name'] ?? null;
                    } else {
                        $late = (int) floor(($first - $start) / self::MIN);
                        if ($late > $shift->grace_in) {
                            $row['late_minutes'] = $late;
                        }
                        if ($hasOut) {
                            $early = (int) floor(($end - $last) / self::MIN);
                            if ($early > $shift->grace_out) {
                                $row['early_minutes'] = $early;
                            }
                        } elseif ($now > $end) {
                            $row['missing_out'] = true;
                        }
                        $row['status'] = $row['late_minutes'] ? 'late' : 'present';
                    }
                } elseif ($off) {
                    $row['status'] = $off;
                    $row['note'] = $holiday['name'] ?? null;
                } elseif ($leave) {
                    $row['status'] = 'leave';
                    $row['leave_type'] = $leave['type'];
                    $row['note'] = __($leave['type']).($leave['note'] ? ' - '.$leave['note'] : '');
                } elseif ($now < $start + $shift->grace_in * self::MIN) {
                    $row['status'] = 'not_yet';
                } else {
                    $row['status'] = 'absent';
                }

                $days[] = $row;

                if ($row['status'] === 'not_yet') {
                    continue;
                }
                if (! $off) {
                    $sum['work_days']++;
                }
                match ($row['status']) {
                    'present', 'late' => $sum['present']++,
                    'absent' => $sum['absent']++,
                    'leave' => $sum['leave']++,
                    'offday_work' => $sum['offday_work']++,
                    default => null,
                };
                if ($row['late_minutes']) {
                    $sum['late_count']++;
                    $sum['late_minutes'] += $row['late_minutes'];
                }
                if ($row['early_minutes']) {
                    $sum['early_count']++;
                    $sum['early_minutes'] += $row['early_minutes'];
                }
                if ($row['missing_out']) {
                    $sum['missing_out']++;
                }
                $sum['worked_minutes'] += $row['worked_minutes'];
            }
            $summary[] = $sum;
        }

        return ['days' => $days, 'summary' => $summary];
    }

    /** "1:05" for 65 minutes, "" for none. */
    public static function duration(?int $minutes): string
    {
        return $minutes ? intdiv($minutes, 60).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) : '';
    }

    public static function statusLabel(?string $status): string
    {
        return $status ? __(self::STATUSES[$status] ?? $status) : '';
    }

    private function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time) + [0, 0]);

        return $h * 60 + $m;
    }

    private function addDays(string $date, int $days): string
    {
        return gmdate('Y-m-d', Clock::seconds($date.' 00:00:00') + $days * self::DAY);
    }
}
