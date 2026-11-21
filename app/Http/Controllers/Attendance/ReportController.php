<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Attendance\Concerns\AttendanceFilters;
use App\Http\Controllers\Controller;
use App\Services\Attendance\BranchTransfer;
use App\Services\Attendance\Calculator;
use App\Services\Attendance\Clock;
use App\Services\Attendance\Exports\AttendanceWorkbook;
use App\Services\Attendance\Exports\TimesheetWorkbook;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A summary per employee for a month or any period, with Excel and timesheet exports. */
class ReportController extends Controller
{
    use AttendanceFilters;

    public function index(Request $request, Calculator $calculator): View
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? $request->query('month') : null;
        $defaultFrom = $month ? $month.'-01' : null;
        $defaultTo = $month ? gmdate('Y-m-t', strtotime($month.'-01 12:00 UTC')) : null;
        [$from, $to] = $this->period($request, $defaultFrom, $defaultTo);
        $departmentId = $this->departmentId($request);

        $result = $calculator->compute($from, $to, $departmentId);
        $pin = (string) $request->query('pin');
        $detail = $pin !== '' ? collect($result['days'])->where('pin', $pin)->values() : null;

        return view('attendance.report', [
            'from' => $from, 'to' => $to, 'month' => $month ?? substr($from, 0, 7),
            'departmentId' => $departmentId,
            'departments' => $this->departmentOptions(),
            'summary' => $result['summary'],
            'pin' => $pin,
            'detail' => $detail,
            'detailEmployee' => $pin !== '' ? collect($result['summary'])->firstWhere('pin', $pin) : null,
            'timesheetMonth' => $month ?? TimesheetWorkbook::monthOf(Clock::today()),
            'branchName' => BranchTransfer::branchName(),
        ]);
    }

    public function export(Request $request, AttendanceWorkbook $workbook): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $book = $workbook->build($from, $to, $this->departmentId($request));
        Activity::log('exported', null, "Excel export {$from} to {$to}");

        return $this->download($book, "attendance_{$from}_{$to}.xlsx");
    }

    public function timesheet(Request $request, TimesheetWorkbook $workbook): StreamedResponse
    {
        $month = (string) $request->query('month');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw ValidationException::withMessages(['month' => __('Pick a month.')]);
        }
        ['book' => $book, 'period' => $period] = $workbook->build($month, $this->departmentId($request));
        Activity::log('exported', null, "Timesheet {$period['from']} to {$period['to']}");

        return $this->download($book, "Timesheet_{$month}.xlsx");
    }

    private function download(Spreadsheet $book, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
