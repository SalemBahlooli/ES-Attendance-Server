<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Attendance\Concerns\AttendanceFilters;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeRequest;
use App\Models\Leave;
use App\Models\User;
use App\Notifications\SimpleNotification;
use App\Services\Attendance\Calculator;
use App\Services\Attendance\Clock;
use App\Services\Attendance\LeaveBalance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The employee portal: the signed-in employee's own attendance, leave balance and
 * requests. An account sees only the employee linked to it (on the employee's page).
 */
class PortalController extends Controller
{
    use AttendanceFilters;

    public function index(Request $request, Calculator $calculator): View
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return view('attendance.portal', ['employee' => null]);
        }

        [$from, $to] = $this->period($request);
        $employee->load(['department.shift', 'shift']);
        $days = EmployeeController::daysFor($employee, $from, $to, $calculator)->sortByDesc('date')->values();
        $kinds = EmployeeRequest::enabledKinds();

        return view('attendance.portal', [
            'employee' => $employee,
            'shift' => $employee->effectiveShift(),
            'from' => $from, 'to' => $to,
            'days' => $days,
            'stats' => Calculator::totals($days),
            'balance' => feature('leave_balances') ? LeaveBalance::for($employee) : null,
            'kinds' => $kinds,
            'requests' => $kinds ? $employee->requests()->enabled()->with('reviewer:id,name')->latest()->limit(20)->get() : collect(),
            'types' => collect(Leave::TYPES)->mapWithKeys(fn ($t) => [$t => __($t)])->all(),
        ]);
    }

    /** The printable sheet of the signed-in employee. */
    public function sheet(Request $request, Calculator $calculator): View
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 404);

        return EmployeeSheetController::render($request, $employee, $calculator, route('portal.index'));
    }

    public function storeRequest(Request $request): RedirectResponse
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 404);

        $kind = $request->input('kind');
        if (! in_array($kind, EmployeeRequest::enabledKinds(), true)) {
            throw ValidationException::withMessages(['kind' => __('This kind of request is turned off.')]);
        }

        if ($kind === 'leave') {
            $data = $request->validate([
                'leave_type' => ['required', Rule::in(Leave::TYPES)],
                'start_date' => ['required', 'date_format:Y-m-d'],
                'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date', 'before_or_equal:'.$this->addDays((string) $request->input('start_date', Clock::today()), 366)],
                'reason' => ['nullable', 'string', 'max:500'],
            ], [], ['leave_type' => __('Type'), 'start_date' => __('From'), 'end_date' => __('To'), 'reason' => __('Reason')]);
            $overlap = Leave::where('employee_id', $employee->id)->where('end_date', '>=', $data['start_date'])->where('start_date', '<=', $data['end_date'])->exists()
                || $employee->requests()->where('kind', 'leave')->where('status', 'pending')
                    ->where('end_date', '>=', $data['start_date'])->where('start_date', '<=', $data['end_date'])->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['start_date' => __('You already have a leave or a request on these days.')]);
            }
        } else {
            // <input type="datetime-local"> sends "2026-11-20T16:05"
            $request->merge(['punch_time' => str_replace('T', ' ', substr((string) $request->input('punch_time'), 0, 16))]);
            $data = $request->validate([
                'punch_time' => ['required', 'date_format:Y-m-d H:i'],
                'reason' => ['required', 'string', 'max:500'],
            ], [], ['punch_time' => __('Time'), 'reason' => __('Reason')]);
            $data['punch_time'] .= ':00';
            if ($data['punch_time'] > Clock::nowStamp()) {
                throw ValidationException::withMessages(['punch_time' => __('Pick a time that has already passed.')]);
            }
            if ($employee->punches()->where('punch_time', $data['punch_time'])->exists()
                || $employee->requests()->where('kind', 'punch')->where('status', 'pending')->where('punch_time', $data['punch_time'])->exists()) {
                throw ValidationException::withMessages(['punch_time' => __('There is already a punch or a request at this time.')]);
            }
        }

        $req = EmployeeRequest::create($data + ['kind' => $kind, 'employee_id' => $employee->id, 'user_id' => $request->user()->id]);

        $reviewers = User::withPermission('requests.review')->reject(fn (User $u) => $u->is($request->user()));
        if ($reviewers->isNotEmpty()) {
            Notification::send($reviewers, new SimpleNotification(
                title: __('New request from :name', ['name' => $employee->name ?: $employee->pin]),
                body: $req->summary(),
                url: route('requests.index'),
                icon: 'fa-inbox',
                color: 'amber',
            ));
        }

        return back()->with('success', __('Request sent. You will be notified when it is reviewed.'));
    }

    public function cancelRequest(Request $request, EmployeeRequest $employeeRequest): RedirectResponse
    {
        $employee = $request->user()->employee;
        abort_unless($employee && $employeeRequest->employee_id === $employee->id, 404);
        abort_unless($employeeRequest->isPending(), 422);

        $employeeRequest->forceFill(['status' => 'cancelled'])->save();

        return back()->with('success', __('Request cancelled.'));
    }
}
