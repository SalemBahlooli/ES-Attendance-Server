<?php

use App\Http\Controllers\Attendance;
use App\Models\DeviceCommand;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Attendance (loaded inside the signed-in group, see routes/web.php)
|--------------------------------------------------------------------------
*/

// Daily attendance
Route::get('attendance', [Attendance\DailyController::class, 'index'])->middleware('can:attendance.view')->name('attendance.index');

// Overview and the period report
Route::middleware('can:reports.view')->group(function () {
    Route::get('overview', [Attendance\OverviewController::class, 'index'])->name('overview.index');
    Route::get('overview/data', [Attendance\OverviewController::class, 'data'])->name('overview.data');
    Route::get('overview/calendar', [Attendance\OverviewController::class, 'calendar'])->name('overview.calendar');

    Route::get('reports', [Attendance\ReportController::class, 'index'])->name('reports.index');
    Route::middleware('can:reports.export')->group(function () {
        Route::get('reports/export', [Attendance\ReportController::class, 'export'])->name('reports.export');
        Route::get('reports/timesheet', [Attendance\ReportController::class, 'timesheet'])->name('reports.timesheet');
        Route::get('reports/branch-file', [Attendance\BranchExportController::class, 'download'])->name('reports.branch-file');
    });
});

// Leaves and public holidays
Route::middleware('can:leaves.view')->group(function () {
    Route::get('leaves', [Attendance\LeaveController::class, 'index'])->name('leaves.index');
    Route::middleware('can:leaves.edit')->group(function () {
        Route::post('leaves', [Attendance\LeaveController::class, 'store'])->name('leaves.store');
        Route::delete('leaves/{leave}', [Attendance\LeaveController::class, 'destroy'])->name('leaves.destroy');
    });
    Route::middleware('can:holidays.edit')->group(function () {
        Route::post('holidays', [Attendance\HolidayController::class, 'store'])->name('leaves.holidays.store');
        Route::delete('holidays/{holiday}', [Attendance\HolidayController::class, 'destroy'])->name('leaves.holidays.destroy');
    });
});

// Employees
Route::middleware('can:employees.view')->group(function () {
    Route::get('employees', [Attendance\EmployeeController::class, 'index'])->name('employees.index');
    Route::get('employees/{employee}', [Attendance\EmployeeController::class, 'show'])->name('employees.show');
    Route::get('employees/{employee}/export', [Attendance\EmployeeController::class, 'export'])->name('employees.export');
    Route::put('employees/{employee}', [Attendance\EmployeeController::class, 'update'])->middleware('can:employees.edit')->name('employees.update');
});

// Punches
Route::middleware('can:punches.view')->group(function () {
    Route::get('punches', [Attendance\PunchController::class, 'index'])->name('punches.index');
    Route::get('punches/day', [Attendance\PunchController::class, 'day'])->name('punches.day');
    Route::middleware('can:punches.edit')->group(function () {
        Route::post('punches', [Attendance\PunchController::class, 'store'])->name('punches.store');
        Route::put('punches/{punch}', [Attendance\PunchController::class, 'update'])->name('punches.update');
        Route::delete('punches/{punch}', [Attendance\PunchController::class, 'destroy'])->name('punches.destroy');
    });
});

// Shifts and departments
Route::middleware('can:shifts.manage')->group(function () {
    Route::get('shifts', [Attendance\ShiftController::class, 'index'])->name('shifts.index');
    Route::post('shifts', [Attendance\ShiftController::class, 'store'])->name('shifts.store');
    Route::put('shifts/{shift}', [Attendance\ShiftController::class, 'update'])->name('shifts.update');
    Route::post('shifts/{shift}/default', [Attendance\ShiftController::class, 'makeDefault'])->name('shifts.default');
    Route::delete('shifts/{shift}', [Attendance\ShiftController::class, 'destroy'])->name('shifts.destroy');

    Route::post('departments', [Attendance\DepartmentController::class, 'store'])->name('shifts.departments.store');
    Route::put('departments/{department}', [Attendance\DepartmentController::class, 'update'])->name('shifts.departments.update');
    Route::delete('departments/{department}', [Attendance\DepartmentController::class, 'destroy'])->name('shifts.departments.destroy');
});

// Devices
Route::middleware('can:devices.view')->group(function () {
    Route::get('devices', [Attendance\DeviceController::class, 'index'])->name('devices.index');
    Route::middleware('can:devices.manage')->group(function () {
        Route::put('devices/{device}', [Attendance\DeviceController::class, 'update'])->name('devices.update');
        Route::post('devices/{device}/command/{action}', [Attendance\DeviceController::class, 'command'])
            ->whereIn('action', array_keys(DeviceCommand::COMMANDS))->name('devices.command');
    });
});

// Import: USB / file, LAN, another branch
Route::middleware('can:imports.manage')->group(function () {
    Route::get('imports', [Attendance\ImportController::class, 'index'])->name('imports.index');
    Route::post('imports/preview', [Attendance\ImportController::class, 'preview'])->name('imports.preview');
    Route::post('imports', [Attendance\ImportController::class, 'store'])->name('imports.store');
    Route::post('imports/lan/test', [Attendance\ImportController::class, 'lanTest'])->name('imports.lan.test');
    Route::post('imports/lan/pull', [Attendance\ImportController::class, 'lanPull'])->name('imports.lan.pull');
});
