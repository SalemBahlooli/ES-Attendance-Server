<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Attendance\Concerns\AttendanceFilters;
use App\Http\Controllers\Controller;
use App\Services\Attendance\BranchTransfer;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/** A file with this branch's attendance for a period, for another branch to import. */
class BranchExportController extends Controller
{
    use AttendanceFilters;

    public function download(Request $request): Response
    {
        [$from, $to] = $this->period($request);
        $name = trim((string) $request->query('branch')) ?: BranchTransfer::branchName();
        if ($name === '') {
            throw ValidationException::withMessages(['branch' => __('Enter the name of this branch.')]);
        }
        $name = mb_substr($name, 0, 100);
        BranchTransfer::saveBranchName($name);

        $pkg = BranchTransfer::export($from, $to, $this->departmentId($request), $name, $request->user()->email);
        Activity::log('exported', null, 'Export for another branch: '.count($pkg['employees']).' employees, '.count($pkg['punches']).' punches', ['from' => $from, 'to' => $to]);

        $safe = trim(preg_replace('/[^\w-]+/u', '_', $name), '_') ?: 'branch';

        return response(json_encode($pkg, JSON_UNESCAPED_UNICODE), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="Attendance_'.$safe.'_'.$from.'_'.$to.'.json"',
        ]);
    }
}
