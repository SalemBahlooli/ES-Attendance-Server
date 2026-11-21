<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Attendance\Concerns\AttendanceFilters;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Attendance\Calculator;
use App\Services\Attendance\Clock;
use App\Services\Attendance\LeaveBalance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * One employee's attendance on one printable page (A4), with lines to sign.
 * The browser prints it or saves it as PDF, so Arabic and the logo come out right.
 */
class EmployeeSheetController extends Controller
{
    use AttendanceFilters;

    public function show(Request $request, Employee $employee, Calculator $calculator): View
    {
        return self::render($request, $employee, $calculator, route('employees.show', ['employee' => $employee] + $request->only('from', 'to')));
    }

    public static function render(Request $request, Employee $employee, Calculator $calculator, string $back): View
    {
        [$from, $to] = (new self)->period($request, null, null, 62);
        $employee->load(['department.shift', 'shift']);
        $days = EmployeeController::daysFor($employee, $from, $to, $calculator);

        return view('attendance.employees.sheet', [
            'employee' => $employee,
            'shift' => $employee->effectiveShift(),
            'from' => $from, 'to' => $to,
            'days' => $days,
            'stats' => Calculator::totals($days),
            'balance' => feature('leave_balances') ? LeaveBalance::for($employee, (int) substr($to, 0, 4)) : null,
            'logo' => setting('logo') ? Storage::disk('public')->url(setting('logo')) : null,
            'printedAt' => Clock::nowStamp(),
            'back' => $back,
        ]);
    }
}
