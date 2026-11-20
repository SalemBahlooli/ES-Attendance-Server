<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Attendance\Concerns\AttendanceFilters;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Punch;
use App\Services\Attendance\Calculator;
use App\Services\Attendance\Clock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Any period at a glance: a calendar of days with punches, charts and an attendance
 * map. The page loads its figures from data() with the same rules as the report.
 */
class OverviewController extends Controller
{
    use AttendanceFilters;

    public function index(): View
    {
        $bounds = Punch::query()->selectRaw('MIN(punch_time) AS first, MAX(punch_time) AS last')->toBase()->first();

        return view('attendance.overview', [
            'today' => Clock::today(),
            'departments' => $this->departmentOptions(),
            'employees' => Employee::where('is_active', true)->ordered()->get(['pin', 'name', 'department_id']),
            'first' => $bounds?->first ? substr((string) $bounds->first, 0, 10) : null,
            'last' => $bounds?->last ? substr((string) $bounds->last, 0, 10) : null,
        ]);
    }

    public function data(Request $request, Calculator $calculator): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $departmentId = $this->departmentId($request);
        $pin = trim((string) $request->query('pin'));

        $result = $calculator->compute($from, $to, $departmentId, $pin !== '' ? [$pin] : null);

        $hours = $this->punches($from, $to, $departmentId, $pin)
            ->selectRaw('SUBSTR(punch_time, 12, 2) AS hour, COUNT(*) AS punches')
            ->groupBy('hour')->orderBy('hour')->toBase()->get()
            ->map(fn ($r) => ['hour' => (int) $r->hour, 'punches' => (int) $r->punches]);

        return response()->json([
            'from' => $from, 'to' => $to, 'today' => Clock::today(),
            'days' => $result['days'], 'summary' => $result['summary'], 'hours' => $hours,
        ]);
    }

    /** Days of a month that have punches, with how many employees punched (calendar shading). */
    public function calendar(Request $request): JsonResponse
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? $request->query('month') : substr(Clock::today(), 0, 7);
        $from = $month.'-01';
        $to = gmdate('Y-m-t', strtotime($from.' 12:00 UTC'));

        $days = $this->punches($from, $to, $this->departmentId($request), trim((string) $request->query('pin')))
            ->selectRaw('SUBSTR(punch_time, 1, 10) AS day, COUNT(*) AS punches, COUNT(DISTINCT pin) AS employees')
            ->groupBy('day')->orderBy('day')->toBase()->get()
            ->map(fn ($r) => ['day' => $r->day, 'punches' => (int) $r->punches, 'employees' => (int) $r->employees]);

        return response()->json(['month' => $month, 'days' => $days]);
    }

    private function punches(string $from, string $to, ?int $departmentId, string $pin): Builder
    {
        return Punch::query()
            ->where('punch_time', '>=', $from.' 00:00:00')
            ->where('punch_time', '<=', $to.' 23:59:59')
            ->when($pin !== '', fn ($q) => $q->where('pin', $pin))
            ->when($departmentId, fn ($q) => $q->whereIn('pin', Employee::where('department_id', $departmentId)->select('pin')))
            ->reorder();
    }
}
