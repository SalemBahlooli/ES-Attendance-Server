<?php

namespace Tests\Feature\Attendance;

use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\Punch;
use App\Models\Shift;
use Database\Seeders\AttendanceDemoSeeder;
use Database\Seeders\AttendanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Every attendance page opens, and nobody without the permission gets in. */
class AttendancePagesTest extends TestCase
{
    use RefreshDatabase;

    public static function locales(): array
    {
        return [['en'], ['ar']];
    }

    #[DataProvider('locales')]
    public function test_every_attendance_page_opens(string $locale): void
    {
        $this->travelTo(now('Asia/Riyadh')->setTime(13, 0));
        $this->seed(AttendanceDemoSeeder::class);
        $user = $this->signInAsSuperUser();
        $user->forceFill(['locale' => $locale])->save();
        $employee = Employee::where('pin', '1')->first();
        $punch = Punch::first();

        $pages = [
            route('dashboard'),
            route('attendance.index'), route('attendance.index', ['date' => substr($punch->time(), 0, 10)]),
            route('overview.index'), route('overview.data', ['from' => now()->subDays(20)->toDateString(), 'to' => now()->toDateString()]),
            route('overview.data', ['from' => now()->toDateString(), 'to' => now()->toDateString()]),
            route('overview.calendar', ['month' => now()->format('Y-m')]),
            route('reports.index'), route('reports.index', ['pin' => '1']), route('reports.index', ['month' => now()->format('Y-m')]),
            route('leaves.index'), route('leaves.index', ['all' => 1]),
            route('employees.index'), route('employees.index', ['inactive' => 1, 'q' => 'Ahmed']), route('employees.show', $employee),
            route('punches.index'), route('punches.day', ['pin' => '1', 'date' => substr($punch->time(), 0, 10)]),
            route('shifts.index'), route('devices.index'), route('imports.index'), route('settings.edit', 'attendance'),
        ];
        foreach ($pages as $url) {
            $this->get($url)->assertOk();
        }

        // Inactive employees still have a page
        $employee->update(['is_active' => false]);
        $this->get(route('employees.show', $employee))->assertOk();
    }

    public static function protectedRoutes(): array
    {
        return [
            'daily' => ['get', 'attendance.index', [], 'attendance.view'],
            'overview' => ['get', 'overview.index', [], 'reports.view'],
            'overview data' => ['get', 'overview.data', [], 'reports.view'],
            'calendar' => ['get', 'overview.calendar', [], 'reports.view'],
            'report' => ['get', 'reports.index', [], 'reports.view'],
            'export' => ['get', 'reports.export', [], 'reports.export'],
            'timesheet' => ['get', 'reports.timesheet', [], 'reports.export'],
            'branch file' => ['get', 'reports.branch-file', [], 'reports.export'],
            'leaves' => ['get', 'leaves.index', [], 'leaves.view'],
            'add leave' => ['post', 'leaves.store', [], 'leaves.edit'],
            'delete leave' => ['delete', 'leaves.destroy', ['leave' => 1], 'leaves.edit'],
            'add holiday' => ['post', 'leaves.holidays.store', [], 'holidays.edit'],
            'delete holiday' => ['delete', 'leaves.holidays.destroy', ['holiday' => 1], 'holidays.edit'],
            'employees' => ['get', 'employees.index', [], 'employees.view'],
            'employee' => ['get', 'employees.show', ['employee' => 1], 'employees.view'],
            'employee csv' => ['get', 'employees.export', ['employee' => 1], 'employees.view'],
            'edit employee' => ['put', 'employees.update', ['employee' => 1], 'employees.edit'],
            'punch log' => ['get', 'punches.index', [], 'punches.view'],
            'punch day' => ['get', 'punches.day', [], 'punches.view'],
            'add punch' => ['post', 'punches.store', [], 'punches.edit'],
            'edit punch' => ['put', 'punches.update', ['punch' => 1], 'punches.edit'],
            'delete punch' => ['delete', 'punches.destroy', ['punch' => 1], 'punches.edit'],
            'shifts' => ['get', 'shifts.index', [], 'shifts.manage'],
            'add shift' => ['post', 'shifts.store', [], 'shifts.manage'],
            'edit shift' => ['put', 'shifts.update', ['shift' => 1], 'shifts.manage'],
            'default shift' => ['post', 'shifts.default', ['shift' => 1], 'shifts.manage'],
            'delete shift' => ['delete', 'shifts.destroy', ['shift' => 1], 'shifts.manage'],
            'add department' => ['post', 'shifts.departments.store', [], 'shifts.manage'],
            'edit department' => ['put', 'shifts.departments.update', ['department' => 1], 'shifts.manage'],
            'delete department' => ['delete', 'shifts.departments.destroy', ['department' => 1], 'shifts.manage'],
            'devices' => ['get', 'devices.index', [], 'devices.view'],
            'rename device' => ['put', 'devices.update', ['device' => 1], 'devices.manage'],
            'device command' => ['post', 'devices.command', ['device' => 1, 'action' => 'check'], 'devices.manage'],
            'imports' => ['get', 'imports.index', [], 'imports.manage'],
            'import preview' => ['post', 'imports.preview', [], 'imports.manage'],
            'import' => ['post', 'imports.store', [], 'imports.manage'],
            'lan test' => ['post', 'imports.lan.test', [], 'imports.manage'],
            'lan pull' => ['post', 'imports.lan.pull', [], 'imports.manage'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_routes_are_forbidden_without_the_permission(string $method, string $route, array $params, string $permission): void
    {
        $this->seed(AttendanceSeeder::class);
        $employee = Employee::create(['pin' => '1']);
        Device::create(['sn' => 'X1']);
        Department::create(['name' => 'D']);
        Holiday::create(['name' => 'H', 'start_date' => '2026-01-01', 'end_date' => '2026-01-01']);
        Leave::create(['employee_id' => $employee->id, 'type' => 'Annual', 'start_date' => '2026-01-01', 'end_date' => '2026-01-01']);
        Punch::create(['sn' => 'X1', 'pin' => '1', 'punch_time' => '2026-01-01 08:00:00']);
        $this->assertNotNull(Shift::find(1));

        // Signed in with everything except this permission (and its children)
        $all = ['attendance.view', 'reports.view', 'reports.export', 'punches.view', 'punches.edit', 'leaves.view', 'leaves.edit',
            'holidays.edit', 'employees.view', 'employees.edit', 'shifts.manage', 'devices.view', 'devices.manage', 'imports.manage'];
        $children = ['reports.view' => ['reports.export'], 'punches.view' => ['punches.edit'], 'leaves.view' => ['leaves.edit', 'holidays.edit'],
            'employees.view' => ['employees.edit'], 'devices.view' => ['devices.manage']];
        $without = array_diff($all, [$permission], $children[$permission] ?? []);
        $this->signInWith(array_values($without));

        $this->{$method.'Json'}(route($route, $params))->assertForbidden();
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(route('attendance.index'))->assertRedirect(route('login'));
    }
}
