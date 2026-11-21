<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Attendance\Concerns\AttendanceFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\EmployeeRequest;
use App\Models\Employee;
use App\Models\Punch;
use App\Models\Shift;
use App\Models\User;
use App\Services\Attendance\Calculator;
use App\Services\Attendance\Clock;
use App\Services\Attendance\LeaveBalance;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

        return view('attendance.employees.show', [
            'employee' => $employee,
            'shift' => $employee->effectiveShift(),
            'from' => $from, 'to' => $to,
            'days' => $days,
            'hideOff' => $request->boolean('hide_off'),
            'stats' => Calculator::totals($days),
            'balance' => feature('leave_balances') ? LeaveBalance::for($employee) : null,
            'assignments' => feature('rosters') ? $employee->assignments()->with('shift:id,name')->where('end_date', '>=', Clock::today())->orderBy('start_date')->limit(10)->get() : collect(),
            'departments' => $this->departmentOptions(),
            'shifts' => Shift::orderByDesc('is_default')->orderBy('name')->pluck('name', 'id')->all(),
            'users' => feature('portal') ? $this->userOptions($employee) : [],
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
            $overtime = feature('overtime');
            fputcsv($out, array_values(array_filter([__('Date'), __('Day'), __('Status'), __('In'), __('Out'), __('Worked'), __('Late (min)'), __('Early leave (min)'),
                $overtime ? __('Overtime') : null, __('All punches'), __('Note')], fn ($v) => $v !== null)));
            foreach ($days as $d) {
                $row = [
                    $d['date'], __($d['day_name']), Calculator::statusLabel($d['status']),
                    $d['first_in'] ? substr($d['first_in'], 11, 5) : '', $d['last_out'] ? substr($d['last_out'], 11, 5) : '',
                    Calculator::duration($d['worked_minutes']), $d['late_minutes'] ?: '', $d['early_minutes'] ?: '',
                ];
                if ($overtime) {
                    $row[] = Calculator::duration($d['overtime_minutes'] ?? 0);
                }
                fputcsv($out, [...$row, implode(' ', $d['times']), $d['note'] ?? '']);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    public function update(EmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $data = $request->validated();
        // Fields of features that are off are not on the form: keep what is saved
        if (! feature('portal')) {
            unset($data['user_id']);
        }
        if (! feature('leave_balances')) {
            unset($data['annual_leave_days']);
        }
        $employee->update($data);

        return back()->with('success', __('Changes saved.'));
    }

    /**
     * Delete the chosen employees. Their leaves, requests and shift schedules go with them;
     * their punches are kept as deleted (like a punch deleted by hand), so the same punches
     * from a re-import or the device are not saved again and the employee does not come back.
     */
    public function destroyMany(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
        ], [], ['ids' => __('Employees')]);

        $employees = Employee::whereIn('id', $data['ids'])->get();
        if ($employees->isEmpty()) {
            return back()->with('error', __('Choose the employees to delete.'));
        }

        $userId = $request->user()->id;
        $punches = DB::transaction(function () use ($employees, $userId) {
            $punches = 0;
            foreach ($employees->pluck('pin')->chunk(500) as $pins) {
                $punches += Punch::whereIn('pin', $pins->all())->update(['deleted_at' => now(), 'deleted_by' => $userId]);
            }
            $employees->each->delete(); // each one is in the activity log

            return $punches;
        });

        $names = $employees->map->label()->take(20)->implode(', ').($employees->count() > 20 ? ', …' : '');
        Activity::log('employees_deleted', null, "Deleted {$employees->count()} employees ({$punches} punches marked deleted): {$names}");

        return redirect()->route('employees.index', $request->only(['q', 'department_id', 'inactive', 'page']))
            ->with('success', trans_choice(':count employee deleted.|:count employees deleted.', $employees->count()));
    }

    /**
     * Accounts that can be linked to this employee: not linked to anyone else.
     *
     * @return array<int, string>
     */
    private function userOptions(Employee $employee): array
    {
        $taken = Employee::whereNotNull('user_id')->whereKeyNot($employee->id)->pluck('user_id');

        return User::query()->whereNotIn('id', $taken)->orderBy('name')->get(['id', 'name', 'email'])
            ->mapWithKeys(fn ($u) => [$u->id => $u->name.' · '.$u->email])->all();
    }

    /**
     * Day rows for one employee. Inactive employees are left out of the attendance
     * rules, so their punches are only grouped by date.
     */
    public static function daysFor(Employee $employee, string $from, string $to, Calculator $calculator): Collection
    {
        return (new self)->days($employee, $from, $to, $calculator);
    }

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
                    'late_minutes' => 0, 'early_minutes' => 0, 'overtime_minutes' => 0, 'missing_out' => false, 'note' => null, 'leave_type' => null,
                    'shift' => null, 'special' => null,
                ];
            })->values();
    }
}
