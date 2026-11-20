<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Attendance\Concerns\AttendanceFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\EmployeeRequest;
use App\Models\Employee;
use App\Models\Punch;
use App\Models\Shift;
use App\Services\Attendance\Calculator;
use App\Services\Attendance\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The people who punch: department, shift, start date, active; and their punches day by day. */
class EmployeeController extends Controller
{
    use AttendanceFilters;

    public function index(Request $request): View
    {
        $showInactive = $request->boolean('inactive');
        $employees = Employee::query()
            ->with(['department:id,name', 'shift:id,name'])
            ->search($request->query('q'))
            ->when(! $showInactive, fn ($q) => $q->where('is_active', true))
            ->when($this->departmentId($request), fn ($q, $id) => $q->where('department_id', $id))
            ->addSelect(['last_punch' => Punch::selectRaw('MAX(punch_time)')->whereColumn('punches.pin', 'employees.pin')])
            ->ordered()
            ->paginate(setting('per_page', 25))
            ->withQueryString();

        return view('attendance.employees.index', [
            'employees' => $employees,
            'showInactive' => $showInactive,
            'departments' => $this->departmentOptions(),
            'shifts' => Shift::orderByDesc('is_default')->orderBy('name')->pluck('name', 'id')->all(),
            'departmentId' => $this->departmentId($request),
        ]);
    }

    public function show(Request $request, Employee $employee, Calculator $calculator): View
    {
        [$from, $to] = $this->period($request);
        $employee->load(['department.shift', 'shift']);
        $days = $this->days($employee, $from, $to, $calculator);
        if ($request->boolean('hide_off')) {
            $days = $days->reject(fn ($d) => in_array($d['status'], ['weekend', 'holiday'], true))->values();
        }

        $counted = $days->reject(fn ($d) => in_array($d['status'], ['not_yet', null], true));
        $workDays = $counted->whereNotIn('status', ['weekend', 'holiday', 'offday_work'])->count();
        $present = $counted->whereIn('status', ['present', 'late'])->count();

        return view('attendance.employees.show', [
            'employee' => $employee,
            'shift' => $employee->effectiveShift(),
            'from' => $from, 'to' => $to,
            'days' => $days,
            'hideOff' => $request->boolean('hide_off'),
            'stats' => [
                'work_days' => $workDays,
                'present' => $present,
                'absent' => $counted->where('status', 'absent')->count(),
                'leave' => $counted->where('status', 'leave')->count(),
                'late_count' => $counted->where('late_minutes', '>', 0)->count(),
                'late_minutes' => $counted->sum('late_minutes'),
                'early_minutes' => $counted->sum('early_minutes'),
                'worked_minutes' => $days->sum('worked_minutes'),
                'missing_out' => $counted->where('missing_out', true)->count(),
            ],
            'departments' => $this->departmentOptions(),
            'shifts' => Shift::orderByDesc('is_default')->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    /** The employee's days as CSV (opens in Excel). */
    public function export(Request $request, Employee $employee, Calculator $calculator): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $days = $this->days($employee, $from, $to, $calculator);
        $filename = 'Attendance_'.preg_replace('/[^\w-]+/u', '_', $employee->name ?: $employee->pin)."_{$from}_{$to}.csv";

        return response()->streamDownload(function () use ($days) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [__('Date'), __('Day'), __('Status'), __('In'), __('Out'), __('Worked'), __('Late (min)'), __('Early leave (min)'), __('All punches'), __('Note')]);
            foreach ($days as $d) {
                fputcsv($out, [
                    $d['date'], __($d['day_name']), Calculator::statusLabel($d['status']),
                    $d['first_in'] ? substr($d['first_in'], 11, 5) : '', $d['last_out'] ? substr($d['last_out'], 11, 5) : '',
                    Calculator::duration($d['worked_minutes']), $d['late_minutes'] ?: '', $d['early_minutes'] ?: '',
                    implode(' ', $d['times']), $d['note'] ?? '',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    public function update(EmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $employee->update($request->validated());

        return back()->with('success', __('Changes saved.'));
    }

    /**
     * Day rows for one employee. Inactive employees are left out of the attendance
     * rules, so their punches are only grouped by date.
     */
    private function days(Employee $employee, string $from, string $to, Calculator $calculator): Collection
    {
        if ($employee->is_active) {
            return collect($calculator->compute($from, $to, null, [$employee->pin])['days']);
        }

        return Punch::where('pin', $employee->pin)
            ->where('punch_time', '>=', $from.' 00:00:00')->where('punch_time', '<=', $to.' 23:59:59')
            ->orderBy('punch_time')->toBase()->get(['punch_time', 'source'])
            ->groupBy(fn ($p) => substr((string) $p->punch_time, 0, 10))
            ->map(function ($list, $date) {
                $times = $list->map(fn ($p) => substr((string) $p->punch_time, 0, 19));
                $first = $times->first();
                $last = $times->count() > 1 ? $times->last() : null;

                return [
                    'date' => $date, 'day_name' => Shift::DAY_NAMES[(int) gmdate('w', strtotime($date.' 12:00 UTC'))],
                    'status' => null, 'first_in' => $first, 'last_out' => $last, 'punches' => $times->count(),
                    'times' => $times->map(fn ($t) => substr($t, 11, 5))->all(),
                    'manual_times' => $list->where('source', 'manual')->map(fn ($p) => substr((string) $p->punch_time, 11, 5))->values()->all(),
                    'worked_minutes' => $last ? intdiv(Clock::seconds($last) - Clock::seconds($first), 60) : 0,
                    'late_minutes' => 0, 'early_minutes' => 0, 'missing_out' => false, 'note' => null, 'leave_type' => null,
                ];
            })->values();
    }
}
