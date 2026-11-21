<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Attendance\Concerns\AttendanceFilters;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Services\Attendance\Clock;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Shift schedules: another shift for one employee (or a whole department) between two
 * dates, or a rotation between two shifts every few weeks. The one added last wins.
 */
class ShiftAssignmentController extends Controller
{
    use AttendanceFilters;

    public function index(Request $request): View
    {
        $past = $request->boolean('past');
        $employeeId = (int) $request->query('employee_id') ?: null;

        $assignments = ShiftAssignment::query()
            ->with(['employee:id,pin,name,department_id', 'employee.department:id,name', 'shift:id,name,start_time,end_time'])
            ->when(! $past, fn ($q) => $q->where('end_date', '>=', Clock::today()))
            ->when($employeeId, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($this->departmentId($request), fn ($q, $id) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $id)))
            ->orderBy('start_date')->orderBy('employee_id')
            ->paginate(setting('per_page', 25))
            ->withQueryString();

        return view('attendance.schedules', [
            'assignments' => $assignments,
            'past' => $past,
            'employeeId' => $employeeId,
            'departmentId' => $this->departmentId($request),
            'employees' => Employee::where('is_active', true)->ordered()->get(['id', 'pin', 'name'])->mapWithKeys(fn ($e) => [$e->id => $e->label()])->all(),
            'departments' => $this->departmentOptions(),
            'shifts' => Shift::orderByDesc('is_default')->orderBy('name')->get()->mapWithKeys(fn ($s) => [$s->id => $s->name.' ('.$s->start().'–'.$s->end().')'])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['nullable', 'required_without:department_id', 'integer', 'exists:employees,id'],
            'department_id' => ['nullable', 'required_without:employee_id', 'integer', 'exists:departments,id'],
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'alt_shift_id' => ['nullable', 'integer', 'exists:shifts,id', 'different:shift_id'],
            'rotate_weeks' => ['nullable', 'required_with:alt_shift_id', 'integer', 'min:1', 'max:8'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], [
            'employee_id' => __('Employee'), 'department_id' => __('Department'), 'shift_id' => __('Shift'),
            'alt_shift_id' => __('Rotate with'), 'rotate_weeks' => __('Every (weeks)'), 'start_date' => __('From'), 'end_date' => __('To'),
        ]);
        if ((strtotime($data['end_date']) - strtotime($data['start_date'])) / 86400 > 366) {
            throw ValidationException::withMessages(['end_date' => __('Pick a period of one year or less.')]);
        }

        $employees = ! empty($data['employee_id'])
            ? Employee::whereKey($data['employee_id'])->get()
            : Employee::where('department_id', $data['department_id'])->where('is_active', true)->get();
        if ($employees->isEmpty()) {
            throw ValidationException::withMessages(['department_id' => __('This department has no active employees.')]);
        }

        // Pieces: one for a plain assignment, or alternating blocks of N weeks
        $pieces = [];
        if (! empty($data['alt_shift_id'])) {
            $block = (int) $data['rotate_weeks'] * 7;
            $shiftsCycle = [(int) $data['shift_id'], (int) $data['alt_shift_id']];
            for ($from = $data['start_date'], $i = 0; $from <= $data['end_date']; $i++) {
                $to = min($this->addDays($from, $block - 1), $data['end_date']);
                $pieces[] = [$shiftsCycle[$i % 2], $from, $to];
                $from = $this->addDays($to, 1);
            }
        } else {
            $pieces[] = [(int) $data['shift_id'], $data['start_date'], $data['end_date']];
        }

        Activity::withoutLogging(function () use ($employees, $pieces, $data, $request) {
            DB::transaction(function () use ($employees, $pieces, $data, $request) {
                foreach ($employees as $employee) {
                    foreach ($pieces as [$shiftId, $from, $to]) {
                        ShiftAssignment::create([
                            'employee_id' => $employee->id, 'shift_id' => $shiftId, 'start_date' => $from, 'end_date' => $to,
                            'note' => $data['note'] ?? null, 'created_by' => $request->user()->id,
                        ]);
                    }
                }
            });
        });

        $shiftNames = Shift::whereIn('id', array_unique(array_column($pieces, 0)))->pluck('name')->implode(' / ');
        $who = $employees->count() === 1 ? $employees->first()->label() : __(':n employees', ['n' => $employees->count()]);
        Activity::log('schedule_added', null, __('Shift schedule').": {$who} · {$shiftNames} ({$data['start_date']} – {$data['end_date']})");

        return back()->with('success', __('Schedule saved: :n entries.', ['n' => count($pieces) * $employees->count()]));
    }

    public function destroy(ShiftAssignment $assignment): RedirectResponse
    {
        $assignment->delete();

        return back()->with('success', __('Deleted.'));
    }
}
