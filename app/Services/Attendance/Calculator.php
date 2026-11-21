<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\Punch;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\SpecialPeriod;
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
 *
 * Optional features (Settings → Optional features):
 *  - Shift schedules: a shift assignment for the date replaces the employee's shift
 *    (the one added last wins).
 *  - Special working hours (Ramadan): other start and end times between two dates, for
 *    one shift or all; grace and days off still come from the shift.
 *  - Flexible hours: on a flexible shift nobody is late; worked time short of the
 *    required hours (beyond the early-leave grace) is counted as early leave, and
 *    overtime is the time worked beyond them.
 *  - Overtime: minutes after the shift end (from the minimum in Settings), and all the
 *    worked time of a worked day off.
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

    /** When the shift changes, keep at least this long after today's end on today. */
    private const END_MARGIN = 2 * 3600;

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

        // Optional features
        $rosters = feature('rosters')
            ? ShiftAssignment::whereIn('employee_id', $employees->pluck('id'))
                ->where('end_date', '>=', $this->addDays($from, -1))->where('start_date', '<=', $this->addDays($to, 1))
                ->orderByDesc('id')->get()
                ->groupBy('employee_id')
                ->map(fn ($list) => $list->map(fn ($a) => ['from' => $a->start_date->toDateString(), 'to' => $a->end_date->toDateString(), 'shift_id' => $a->shift_id])->all())
            : collect();
        $specials = feature('special_hours')
            ? SpecialPeriod::where('end_date', '>=', $this->addDays($from, -1))->where('start_date', '<=', $this->addDays($to, 1))
                ->orderByRaw('shift_id IS NULL')->orderByDesc('id')->get()
                ->map(fn ($p) => ['from' => $p->start_date->toDateString(), 'to' => $p->end_date->toDateString(), 'shift_id' => $p->shift_id,
                    'name' => $p->name, 'start' => $p->start(), 'end' => $p->end()])
                ->all()
            : [];
        $overtime = feature('overtime');
        $overtimeFrom = max(0, (int) setting('overtime_min_minutes', 30));

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
            $empLeaves = $leaves[$emp->pin] ?? [];
            $empRoster = $rosters[$emp->id] ?? [];

            // The shift and times that apply on one date
            $dayShift = function (string $date) use ($shift, $shifts, $empRoster, $specials): array {
                $s = $shift;
                foreach ($empRoster as $a) {
                    if ($a['from'] <= $date && $date <= $a['to'] && $shifts->has($a['shift_id'])) {
                        $s = $shifts->get($a['shift_id']);
                        break;
                    }
                }
                $start = $s->start();
                $end = $s->end();
                $special = null;
                foreach ($specials as $p) {
                    if ($p['from'] <= $date && $date <= $p['to'] && ($p['shift_id'] === null || (int) $p['shift_id'] === $s->id)) {
                        [$start, $end, $special] = [$p['start'], $p['end'], $p['name']];
                        break;
                    }
                }
                $startMin = $this->minutes($start);

                return [
                    'shift' => $s, 'start' => $start, 'end' => $end, 'special' => $special, 'startMin' => $startMin,
                    'lengthMin' => (($this->minutes($end) - $startMin + 1440) % 1440) ?: 1440,
                ];
            };

            $sum = [
                'employee_id' => $emp->id, 'pin' => $emp->pin, 'name' => $emp->name, 'department_id' => $emp->department_id, 'department' => $emp->department?->name,
                'shift' => $shift->name, 'work_days' => 0, 'present' => 0, 'absent' => 0, 'leave' => 0, 'late_count' => 0, 'late_minutes' => 0,
                'early_count' => 0, 'early_minutes' => 0, 'missing_out' => 0, 'offday_work' => 0, 'worked_minutes' => 0,
                'overtime_minutes' => 0,
            ];

            $i = 0;
            $n = count($punches);
            // Where a day's window ends: 4 hours before the next day's shift (24 hours when the
            // times do not change). When the shift changes and that is too close to the day's end
            // (a night shift followed by a morning one), halfway between the two, so the check-out
            // stays on its own day.
            $windowEnd = function (int $d, array $info, array $next): int {
                $end = $d + ($info['startMin'] + $info['lengthMin']) * self::MIN;
                $nextStart = $d + self::DAY + $next['startMin'] * self::MIN;
                $winEnd = $nextStart - self::WINDOW_BEFORE;

                return $winEnd < $end + self::END_MARGIN && $nextStart > $end ? intdiv($end + $nextStart, 2) : $winEnd;
            };

            $next = $dayShift(gmdate('Y-m-d', $firstDay));
            $carry = $windowEnd($firstDay - self::DAY, $dayShift(gmdate('Y-m-d', $firstDay - self::DAY)), $next);
            for ($d = $firstDay; $d <= $lastDay; $d += self::DAY) {
                $date = gmdate('Y-m-d', $d);
                $dayInfo = $next;
                $next = $dayShift(gmdate('Y-m-d', $d + self::DAY));
                $dayShiftModel = $dayInfo['shift'];
                $start = $d + $dayInfo['startMin'] * self::MIN;
                $end = $start + $dayInfo['lengthMin'] * self::MIN;
                $winStart = $carry;
                $winEnd = max($windowEnd($d, $dayInfo, $next), $winStart + self::MIN);
                $carry = $winEnd;

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
                $off = $holiday ? 'holiday' : (in_array($dow, $dayShiftModel->weekendDays(), true) ? 'weekend' : null);

                $row = [
                    'date' => $date, 'day_name' => Shift::DAY_NAMES[$dow], 'employee_id' => $emp->id, 'pin' => $emp->pin, 'name' => $emp->name,
                    'department_id' => $emp->department_id, 'department' => $emp->department?->name,
                    'shift' => $dayShiftModel->name, 'shift_start' => $dayInfo['start'], 'shift_end' => $dayInfo['end'], 'special' => $dayInfo['special'],
                    'first_in' => null, 'last_out' => null, 'punches' => count($ps),
                    'times' => array_map(fn ($p) => gmdate('H:i', $p), $ps),
                    'manual_times' => array_values(array_map(fn ($p) => gmdate('H:i', $p), array_filter($ps, fn ($p) => isset($manual[$emp->pin.'|'.$p])))),
                    'worked_minutes' => 0, 'late_minutes' => 0, 'early_minutes' => 0, 'overtime_minutes' => 0, 'missing_out' => false,
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
                        if ($overtime && $row['worked_minutes'] >= max(1, $overtimeFrom)) {
                            $row['overtime_minutes'] = $row['worked_minutes'];
                        }
                    } elseif ($dayShiftModel->isFlexible()) {
                        // Only the hours count; Ramadan hours shorten them like the shift
                        $required = $dayInfo['special'] ? $dayInfo['lengthMin'] : $dayShiftModel->requiredMinutes();
                        if ($hasOut) {
                            $short = $required - $row['worked_minutes'];
                            if ($short > $dayShiftModel->grace_out) {
                                $row['early_minutes'] = $short;
                            }
                            $extra = $row['worked_minutes'] - $required;
                            if ($overtime && $extra > 0 && $extra >= $overtimeFrom) {
                                $row['overtime_minutes'] = $extra;
                            }
                        } elseif ($now > $end) {
                            $row['missing_out'] = true;
                        }
                        $row['status'] = 'present';
                        $row['note'] = __('Flexible: :worked of :required', ['worked' => self::duration($row['worked_minutes']) ?: '0:00', 'required' => self::duration($required)]);
                    } else {
                        $late = (int) floor(($first - $start) / self::MIN);
                        if ($late > $dayShiftModel->grace_in) {
                            $row['late_minutes'] = $late;
                        }
                        if ($hasOut) {
                            $early = (int) floor(($end - $last) / self::MIN);
                            if ($early > $dayShiftModel->grace_out) {
                                $row['early_minutes'] = $early;
                            }
                            $after = (int) floor(($last - $end) / self::MIN);
                            if ($overtime && $after > 0 && $after >= $overtimeFrom) {
                                $row['overtime_minutes'] = $after;
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
                } elseif ($now < $start + $dayShiftModel->grace_in * self::MIN) {
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
                $sum['overtime_minutes'] += $row['overtime_minutes'];
            }
            $summary[] = $sum;
        }

        return ['days' => $days, 'summary' => $summary];
    }

    /**
     * Totals of one employee's day rows (employee page, portal, printable sheet).
     *
     * @param  iterable<int, array<string, mixed>>  $days
     * @return array<string, int>
     */
    public static function totals(iterable $days): array
    {
        $days = collect($days);
        $counted = $days->reject(fn ($d) => in_array($d['status'], ['not_yet', null], true));

        return [
            'work_days' => $counted->whereNotIn('status', ['weekend', 'holiday', 'offday_work'])->count(),
            'present' => $counted->whereIn('status', ['present', 'late'])->count(),
            'absent' => $counted->where('status', 'absent')->count(),
            'leave' => $counted->where('status', 'leave')->count(),
            'offday_work' => $counted->where('status', 'offday_work')->count(),
            'late_count' => $counted->where('late_minutes', '>', 0)->count(),
            'late_minutes' => (int) $counted->sum('late_minutes'),
            'early_minutes' => (int) $counted->sum('early_minutes'),
            'overtime_minutes' => (int) $counted->sum('overtime_minutes'),
            'worked_minutes' => (int) $days->sum('worked_minutes'),
            'missing_out' => $counted->where('missing_out', true)->count(),
        ];
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
