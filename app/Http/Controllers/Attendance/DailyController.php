<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Attendance\Concerns\AttendanceFilters;
use App\Http\Controllers\Controller;
use App\Services\Attendance\Calculator;
use App\Services\Attendance\Clock;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Who is present, late, absent, on leave or not in yet, for one day. */
class DailyController extends Controller
{
    use AttendanceFilters;

    public function index(Request $request, Calculator $calculator): View
    {
        $date = $request->query('date');
        $date = $this->isDate($date) ? $date : Clock::today();
        $departmentId = $this->departmentId($request);

        $rows = collect($calculator->compute($date, $date, $departmentId)['days']);
        $count = fn (string ...$statuses) => $rows->whereIn('status', $statuses)->count();

        return view('attendance.daily', [
            'date' => $date,
            'today' => Clock::today(),
            'rows' => $rows,
            'departmentId' => $departmentId,
            'departments' => $this->departmentOptions(),
            'stats' => [
                'present' => $count('present', 'late', 'offday_work'),
                'late' => $count('late'),
                'absent' => $count('absent'),
                'leave' => $count('leave'),
                'not_yet' => $count('not_yet'),
            ],
            'previous' => $this->addDays($date, -1),
            'next' => $this->addDays($date, 1),
        ]);
    }
}
