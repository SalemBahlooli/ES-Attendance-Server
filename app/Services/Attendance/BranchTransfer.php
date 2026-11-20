<?php

namespace App\Services\Attendance;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\Punch;
use App\Models\Shift;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sharing attendance between branches that each run their own copy of this program.
 *
 * Branch A exports a file for a period with only its current data (after corrections);
 * branch B imports it. Employee numbers are company-wide, so the same number is the
 * same person in every branch. On import, what B got from A earlier for those employees
 * and that period is made to match the file: missing items are taken out, new ones added.
 * B's own punches (its devices, its own corrections) are never touched.
 */
class BranchTransfer
{
    public const TYPE = 'es-attendance-branch-export';

    public const VERSION = 1;

    public static function branchName(): string
    {
        return (string) setting('branch_name', '');
    }

    public static function saveBranchName(string $name): void
    {
        if ($name !== self::branchName()) {
            app(Settings::class)->set(['branch_name' => $name]);
        }
    }

    /* ------------------------------------------------------------ export */

    public static function export(string $from, string $to, ?int $departmentId, string $branch, ?string $username): array
    {
        $default = Shift::default();
        $employees = Employee::query()
            ->with(['department.shift', 'shift'])
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            // Active employees (so absences carry over too) and anyone with punches in the period
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('pin', Punch::query()
                ->where('punch_time', '>=', $from.' 00:00:00')->where('punch_time', '<=', $to.' 23:59:59')->select('pin')))
            ->ordered()
            ->get();
        $pins = $employees->pluck('pin')->all();

        $punches = [];
        foreach (array_chunk($pins, 500) as $chunk) {
            Punch::query()->toBase()->whereIn('pin', $chunk)
                ->where('punch_time', '>=', $from.' 00:00:00')->where('punch_time', '<=', $to.' 23:59:59')
                ->whereNull('deleted_at')
                ->orderBy('punch_time')
                ->select('pin', 'punch_time', 'source')
                ->get()
                ->each(function ($p) use (&$punches) {
                    $punches[] = ['pin' => $p->pin, 'time' => substr((string) $p->punch_time, 0, 19), 'manual' => $p->source === 'manual'];
                });
        }
        usort($punches, fn ($a, $b) => strcmp($a['time'], $b['time']));

        $leaves = Leave::query()->with('employee:id,pin')
            ->whereIn('employee_id', $employees->pluck('id'))
            ->where('end_date', '>=', $from)->where('start_date', '<=', $to)
            ->orderBy('start_date')->get()
            ->map(fn ($l) => [
                'pin' => $l->employee->pin, 'type' => $l->type, 'start_date' => $l->start_date->toDateString(),
                'end_date' => $l->end_date->toDateString(), 'note' => $l->note,
            ])->all();

        return [
            'type' => self::TYPE, 'version' => self::VERSION,
            'branch' => ['name' => $branch],
            'period' => ['from' => $from, 'to' => $to],
            'exported_at' => now()->toIso8601String(),
            'exported_by' => $username,
            'employees' => $employees->map(function (Employee $e) use ($default) {
                $shift = $e->shift ?? $e->department?->shift ?? $default;

                return [
                    'pin' => $e->pin, 'name' => $e->name, 'department' => $e->department?->name,
                    'start_date' => $e->start_date?->toDateString(), 'active' => $e->is_active,
                    'shift' => $shift ? [
                        'name' => $shift->name, 'start' => $shift->start(), 'end' => $shift->end(),
                        'grace_in' => $shift->grace_in, 'grace_out' => $shift->grace_out, 'weekend' => $shift->weekendDays(),
                    ] : null,
                ];
            })->all(),
            'punches' => $punches,
            'leaves' => $leaves,
        ];
    }

    /* ------------------------------------------------------------ import */

    public static function validate(mixed $pkg): void
    {
        if (! is_array($pkg) || ($pkg['type'] ?? null) !== self::TYPE) {
            throw new RuntimeException(__('This is not a branch attendance file from this program.'));
        }
        if (($pkg['version'] ?? 0) > self::VERSION) {
            throw new RuntimeException(__('This file was made by a newer version of the program. Update this server first.'));
        }
        if (! is_array($pkg['employees'] ?? null) || ! is_array($pkg['punches'] ?? null)) {
            throw new RuntimeException(__('The file is incomplete.'));
        }
        if (count($pkg['punches']) > PunchImporter::MAX_ROWS) {
            throw new RuntimeException(__('The file is too large.'));
        }
        if (! self::isDate($pkg['period']['from'] ?? null) || ! self::isDate($pkg['period']['to'] ?? null)) {
            throw new RuntimeException(__('The file has no valid period.'));
        }
    }

    public static function preview(array $pkg): array
    {
        self::validate($pkg);
        $pins = self::pins($pkg);
        $here = Employee::whereIn('pin', $pins)->pluck('pin')->flip();
        $sn = self::sourceOf($pkg);
        $note = self::syncNote($pkg);
        $file = self::filePunches($pkg);
        $existing = PunchImporter::existingKeys($file);

        // Punches the import really adds: unknown here, or taken out earlier by a file from
        // this branch (a punch someone here deleted or corrected stays as it is)
        $fresh = 0;
        $keys = [];
        foreach ($file as $p) {
            $k = $p['pin'].'|'.$p['time'];
            $keys[$k] = true;
            $row = $existing[$k] ?? null;
            if (! $row || ($row->deleted_at && $row->sn === $sn && $row->note === $note)) {
                $fresh++;
            }
        }

        return [
            'branch' => $pkg['branch'] ?? null, 'period' => $pkg['period'],
            'exported_at' => $pkg['exported_at'] ?? null, 'exported_by' => $pkg['exported_by'] ?? null,
            'employees' => array_map(fn ($e) => [
                'pin' => (string) $e['pin'], 'name' => $e['name'] ?? null, 'department' => $e['department'] ?? null,
                'exists' => isset($here[(string) $e['pin']]),
            ], $pkg['employees']),
            'punches' => count($file), 'new_punches' => $fresh,
            'removed' => self::staleQuery($sn, $pins, $pkg['period'])->get(['pin', 'punch_time'])
                ->reject(fn ($p) => isset($keys[$p->pin.'|'.substr((string) $p->punch_time, 0, 19)]))->count(),
            'leaves' => count($pkg['leaves'] ?? []),
        ];
    }

    public static function apply(array $pkg, bool $withLeaves, ?int $userId): array
    {
        self::validate($pkg);

        return DB::transaction(function () use ($pkg, $withLeaves, $userId) {
            $branchName = self::nameOf($pkg);

            // Employees: new ones get name, department and shift from the file; employees
            // already here keep what is set here, only empty fields are filled in
            $shiftCache = [];
            $deptCache = [];
            $newEmployees = 0;
            foreach ($pkg['employees'] as $e) {
                $pin = trim((string) ($e['pin'] ?? ''));
                if ($pin === '') {
                    continue;
                }
                $shiftId = self::findOrCreateShift($e['shift'] ?? null, $branchName, $shiftCache);
                $departmentId = self::findOrCreateDepartment($e['department'] ?? null, $deptCache);
                $start = self::isDate($e['start_date'] ?? null) ? $e['start_date'] : null;

                $employee = Employee::firstWhere('pin', $pin);
                if (! $employee) {
                    Employee::create([
                        'pin' => $pin, 'name' => $e['name'] ?? null, 'department_id' => $departmentId,
                        'shift_id' => $shiftId, 'start_date' => $start, 'is_active' => true,
                    ]);
                    $newEmployees++;

                    continue;
                }
                $employee->fill(array_filter([
                    'name' => $employee->name ?: ($e['name'] ?? null),
                    'department_id' => $employee->department_id ?? $departmentId,
                    'shift_id' => $employee->shift_id ?? $shiftId,
                    'start_date' => $employee->start_date?->toDateString() ?? $start,
                ], fn ($v) => $v !== null))->save();
            }

            $sn = self::sourceOf($pkg);
            $note = self::syncNote($pkg);
            $pins = self::pins($pkg);
            $file = self::filePunches($pkg);
            $keys = [];
            foreach ($file as $p) {
                $keys[$p['pin'].'|'.$p['time']] = $p['manual'];
            }

            // 1. Copies taken out by an earlier file that are back in this one
            $revived = 0;
            foreach (PunchImporter::existingKeys($file) as $k => $row) {
                if ($row->deleted_at && $row->sn === $sn && $row->note === $note) {
                    Punch::withTrashed()->whereKey($row->id)->update([
                        'deleted_at' => null, 'deleted_by' => null, 'note' => null,
                        'source' => $keys[$k] ? 'manual' : 'branch',
                    ]);
                    $revived++;
                }
            }

            // 2. New punches (corrections made at the other branch stay marked as manual)
            $a = PunchImporter::save(array_values(array_filter($file, fn ($p) => ! $p['manual'])), $sn, 'branch');
            $b = PunchImporter::save(array_values(array_filter($file, fn ($p) => $p['manual'])), $sn, 'manual');

            // 3. Copies from this branch that the latest file no longer has
            $removed = 0;
            foreach (self::staleQuery($sn, $pins, $pkg['period'])->get(['id', 'pin', 'punch_time']) as $p) {
                if (! isset($keys[$p->pin.'|'.substr((string) $p->punch_time, 0, 19)])) {
                    Punch::whereKey($p->id)->update(['deleted_at' => now(), 'deleted_by' => $userId, 'note' => $note]);
                    $removed++;
                }
            }

            // Leaves from this branch for these employees and period become exactly the file's
            $leaves = 0;
            if ($withLeaves) {
                $ids = Employee::whereIn('pin', $pins)->pluck('id', 'pin');
                $list = collect($pkg['leaves'] ?? [])
                    ->filter(fn ($l) => self::isDate($l['start_date'] ?? null) && self::isDate($l['end_date'] ?? null) && ! empty($l['type']) && isset($ids[(string) ($l['pin'] ?? '')]))
                    ->map(fn ($l) => [
                        'employee_id' => $ids[(string) $l['pin']], 'type' => mb_substr((string) $l['type'], 0, 40),
                        'start_date' => $l['start_date'], 'end_date' => $l['end_date'], 'note' => $l['note'] ?? null,
                    ]);
                $wanted = $list->mapWithKeys(fn ($l) => [$l['employee_id'].'|'.$l['type'].'|'.$l['start_date'].'|'.$l['end_date'] => $l]);

                Leave::where('source', $sn)->whereIn('employee_id', $ids->values())
                    ->where('end_date', '>=', $pkg['period']['from'])->where('start_date', '<=', $pkg['period']['to'])
                    ->get()
                    ->reject(fn ($l) => $wanted->has($l->employee_id.'|'.$l->type.'|'.$l->start_date->toDateString().'|'.$l->end_date->toDateString()))
                    ->each->delete();

                foreach ($wanted as $l) {
                    $exists = Leave::where('employee_id', $l['employee_id'])->where('type', $l['type'])
                        ->whereDate('start_date', $l['start_date'])->whereDate('end_date', $l['end_date'])->exists();
                    if (! $exists) {
                        Leave::create($l + ['source' => $sn, 'created_by' => $userId]);
                        $leaves++;
                    }
                }
            }

            $inserted = $a['inserted'] + $b['inserted'] + $revived;
            $total = $a['total'] + $b['total'];

            return [
                'sn' => $sn, 'branch_name' => $branchName, 'new_employees' => $newEmployees, 'employees' => count($pkg['employees']),
                'total' => $total, 'inserted' => $inserted, 'duplicates' => max(0, $total - $inserted), 'removed' => $removed, 'leaves' => $leaves,
            ];
        });
    }

    /* ------------------------------------------------------------ helpers */

    private static function nameOf(array $pkg): string
    {
        return trim((string) ($pkg['branch']['name'] ?? '')) ?: 'Other branch';
    }

    public static function sourceOf(array $pkg): string
    {
        return mb_substr('BRANCH:'.self::nameOf($pkg), 0, 120);
    }

    private static function syncNote(array $pkg): string
    {
        return mb_substr('Not in the latest file from '.self::nameOf($pkg), 0, 255);
    }

    private static function pins(array $pkg): array
    {
        return array_values(array_filter(array_map(fn ($e) => trim((string) ($e['pin'] ?? '')), $pkg['employees']), fn ($p) => $p !== ''));
    }

    private static function filePunches(array $pkg): array
    {
        $out = [];
        foreach ($pkg['punches'] as $p) {
            $time = (string) ($p['time'] ?? '');
            $pin = trim((string) ($p['pin'] ?? ''));
            if ($pin !== '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $time)) {
                $out[] = ['pin' => $pin, 'time' => $time, 'manual' => (bool) ($p['manual'] ?? false), 'status' => null, 'verify' => null, 'raw' => null];
            }
        }

        return $out;
    }

    /** Live copies from this branch, for these employees and period. */
    private static function staleQuery(string $sn, array $pins, array $period)
    {
        return Punch::query()->where('sn', $sn)->whereIn('pin', $pins ?: ['__none__'])
            ->where('punch_time', '>=', $period['from'].' 00:00:00')
            ->where('punch_time', '<=', $period['to'].' 23:59:59');
    }

    private static function findOrCreateShift(?array $shift, string $branchName, array &$cache): ?int
    {
        if (! $shift || ! preg_match('/^\d{2}:\d{2}$/', (string) ($shift['start'] ?? '')) || ! preg_match('/^\d{2}:\d{2}$/', (string) ($shift['end'] ?? ''))) {
            return null;
        }
        $weekend = collect($shift['weekend'] ?? [])->map(fn ($d) => (int) $d)->filter(fn ($d) => $d >= 0 && $d <= 6)->unique()->sort()->values()->all();
        $graceIn = (int) ($shift['grace_in'] ?? 0);
        $graceOut = (int) ($shift['grace_out'] ?? 0);
        $key = implode('|', [$shift['start'], $shift['end'], $graceIn, $graceOut, implode('', $weekend)]);
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        // Reuse a shift here with exactly the same times, grace and weekend
        $match = Shift::orderByDesc('is_default')->orderBy('id')->get()->first(function (Shift $s) use ($shift, $weekend, $graceIn, $graceOut) {
            $days = $s->weekendDays();
            sort($days);

            return $s->start() === $shift['start'] && $s->end() === $shift['end'] && $s->grace_in === $graceIn && $s->grace_out === $graceOut && $days === $weekend;
        });
        $match ??= Shift::create([
            'name' => mb_substr($branchName.' - '.($shift['name'] ?? 'Shift'), 0, 100),
            'start_time' => $shift['start'], 'end_time' => $shift['end'],
            'grace_in' => $graceIn, 'grace_out' => $graceOut, 'weekend' => $weekend,
        ]);

        return $cache[$key] = $match->id;
    }

    private static function findOrCreateDepartment(?string $name, array &$cache): ?int
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $key = mb_strtolower($name);

        return $cache[$key] ??= (Department::whereRaw('LOWER(name) = ?', [$key])->value('id') ?? Department::create(['name' => $name])->id);
    }

    private static function isDate(mixed $s): bool
    {
        return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
    }
}
