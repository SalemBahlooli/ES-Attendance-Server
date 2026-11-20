<?php

namespace Tests\Feature\Attendance;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\Punch;
use App\Models\Shift;
use App\Services\Attendance\Exports\TimesheetWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Punch corrections, leaves, shifts, departments, employees, devices and exports. */
class ManageAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Shift::create(['name' => 'Standard', 'start_time' => '08:00', 'end_time' => '16:00', 'grace_in' => 15, 'grace_out' => 0, 'weekend' => [5, 6], 'is_default' => true]);
    }

    public function test_punches_are_added_edited_and_deleted_by_hand(): void
    {
        $user = $this->signInWith(['punches.view', 'punches.edit']);
        $device = Punch::create(['sn' => 'DEV', 'pin' => '1', 'punch_time' => '2026-11-16 08:01:00']);

        $this->postJson(route('punches.store'), ['pin' => '1', 'time' => '2026-11-16 16:00', 'note' => 'Forgot to punch'])->assertOk();
        $this->postJson(route('punches.store'), ['pin' => '1', 'time' => '2026-11-16 16:00'])->assertStatus(422)->assertJsonValidationErrors('time');

        $added = Punch::where('source', 'manual')->first();
        $this->assertSame($user->id, $added->created_by);
        $this->assertTrue(Employee::where('pin', '1')->exists());

        // Editing keeps the original (deleted) and adds a corrected manual punch
        $this->putJson(route('punches.update', $device), ['time' => '2026-11-16 07:58'])->assertOk();
        $this->assertSoftDeleted($device);
        $this->assertSame($user->id, $device->fresh()->deleted_by);
        $this->assertSame($device->id, Punch::where('punch_time', '2026-11-16 07:58:00')->value('replaces_id'));

        $this->deleteJson(route('punches.destroy', $added), ['note' => 'Double'])->assertOk();
        $this->assertSoftDeleted($added);

        $this->getJson(route('punches.day', ['pin' => '1', 'date' => '2026-11-16']))->assertOk()
            ->assertJsonCount(1, 'punches')->assertJsonPath('punches.0.time', '2026-11-16 07:58:00');
        $this->assertSame(3, ActivityLog::whereIn('action', ['punch_added', 'punch_edited', 'punch_deleted'])->count());

        // Adding a punch where one was deleted brings that row back as manual
        $this->postJson(route('punches.store'), ['pin' => '1', 'time' => '2026-11-16 08:01'])->assertOk();
        $this->assertSame('manual', $device->fresh()->source);
        $this->assertNull($device->fresh()->deleted_at);
    }

    public function test_leaves_and_holidays(): void
    {
        $this->signInWith(['leaves.view', 'leaves.edit', 'holidays.edit']);
        $employee = Employee::create(['pin' => '1', 'name' => 'Saad']);

        $this->post(route('leaves.store'), ['employee_id' => $employee->id, 'type' => 'Annual', 'start_date' => '2026-11-20', 'end_date' => '2026-11-22'])->assertSessionHasNoErrors();
        $this->post(route('leaves.store'), ['employee_id' => $employee->id, 'type' => 'Holiday?', 'start_date' => '2026-11-22', 'end_date' => '2026-11-20'])
            ->assertSessionHasErrorsIn('leave', ['type', 'end_date']);
        $this->post(route('leaves.holidays.store'), ['name' => 'National Day', 'start_date' => '2026-09-23', 'end_date' => '2026-09-23'])->assertSessionHasNoErrors();

        $this->assertSame(3, Leave::first()->days());
        $this->get(route('leaves.index', ['all' => 1]))->assertOk()->assertSee('Saad')->assertSee('National Day');

        $this->delete(route('leaves.destroy', Leave::first()))->assertRedirect();
        $this->delete(route('leaves.holidays.destroy', Holiday::first()))->assertRedirect();
        $this->assertSame(0, Leave::count() + Holiday::count());
    }

    public function test_shifts_and_departments(): void
    {
        $this->signInWith(['shifts.manage']);
        $default = Shift::first();

        $this->post(route('shifts.store'), ['name' => 'Night', 'start_time' => '20:00', 'end_time' => '04:00', 'grace_in' => 10, 'grace_out' => 5, 'weekend' => ['5', '5', '6']])->assertSessionHasNoErrors();
        $night = Shift::where('name', 'Night')->first();
        $this->assertSame([5, 6], $night->weekendDays());
        $this->assertFalse($night->is_default);

        $this->post(route('shifts.store'), ['name' => '', 'start_time' => '25:00'])->assertSessionHasErrorsIn('shiftnew', ['name', 'start_time']);

        $this->post(route('shifts.default', $night))->assertRedirect();
        $this->assertTrue($night->fresh()->is_default);
        $this->assertFalse($default->fresh()->is_default);

        $this->delete(route('shifts.destroy', $night))->assertSessionHas('error');
        $this->delete(route('shifts.destroy', $default))->assertSessionHas('success');

        $this->post(route('shifts.departments.store'), ['name' => 'Workshop', 'shift_id' => $night->id])->assertSessionHasNoErrors();
        $dept = Department::first();
        $this->put(route('shifts.departments.update', $dept), ['name' => 'Garage', 'shift_id' => ''])->assertSessionHasNoErrors();
        $this->assertSame('Garage', $dept->fresh()->name);
        $this->assertNull($dept->fresh()->shift_id);
    }

    public function test_employee_details_are_edited(): void
    {
        $this->signInWith(['employees.view', 'employees.edit']);
        $employee = Employee::create(['pin' => '1']);
        $dept = Department::create(['name' => 'Sales']);

        $this->put(route('employees.update', $employee), ['name' => 'Saad', 'department_id' => $dept->id, 'shift_id' => '', 'start_date' => '2026-01-05', 'is_active' => '0'])
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertSame('Saad', $employee->name);
        $this->assertFalse($employee->is_active);
        $this->assertSame('2026-01-05', $employee->start_date->toDateString());
        $this->assertDatabaseHas('activity_logs', ['action' => 'updated', 'subject_id' => $employee->id]);
    }

    public function test_devices_are_renamed_and_given_commands(): void
    {
        $this->signInWith(['devices.view', 'devices.manage']);
        $device = Device::create(['sn' => 'ABC', 'last_seen_at' => now()]);

        $this->put(route('devices.update', $device), ['name' => 'Main gate'])->assertRedirect();
        $this->post(route('devices.command', [$device, 'sync-users']))->assertRedirect();
        $this->post(route('devices.command', [$device, 'format-disk']))->assertNotFound();

        $this->assertSame('Main gate', $device->fresh()->name);
        $this->assertSame('DATA QUERY USERINFO', DeviceCommand::first()->command);
        $this->get(route('devices.index'))->assertOk()->assertSee('Main gate');
    }

    public function test_excel_timesheet_and_csv_downloads(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 12:00:00', 'UTC'));
        $this->signInWith(['reports.view', 'reports.export', 'employees.view']);
        $employee = Employee::create(['pin' => '1', 'name' => 'Saad']);
        Punch::create(['sn' => 'X', 'pin' => '1', 'punch_time' => '2026-11-02 08:00:00']);
        Punch::create(['sn' => 'X', 'pin' => '1', 'punch_time' => '2026-11-02 16:00:00']);

        $excel = $this->get(route('reports.export', ['from' => '2026-11-01', 'to' => '2026-11-15']))->assertOk();
        $this->assertStringContainsString('attendance_2026-11-01_2026-11-15.xlsx', $excel->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('PK', $excel->streamedContent());

        $sheet = $this->get(route('reports.timesheet', ['month' => '2026-11']))->assertOk();
        $this->assertStringStartsWith('PK', $sheet->streamedContent());
        $this->get(route('reports.timesheet', ['month' => 'nope']))->assertSessionHasErrors('month');

        $csv = $this->get(route('employees.export', ['employee' => $employee, 'from' => '2026-11-01', 'to' => '2026-11-03']))->assertOk();
        $this->assertStringContainsString('08:00', $csv->streamedContent());
    }

    public function test_the_timesheet_period_runs_from_the_16th_to_the_15th(): void
    {
        $p = TimesheetWorkbook::period('2026-03');
        $this->assertSame('2026-02-16', $p['from']);
        $this->assertSame('2026-03-15', $p['to']);
        $this->assertCount(28, $p['days']);
        $this->assertSame('2025-12-16', TimesheetWorkbook::period('2026-01')['from']);

        $this->assertSame('V', TimesheetWorkbook::dayCode(['status' => 'leave', 'leave_type' => 'Annual']));
        $this->assertSame('LB', TimesheetWorkbook::dayCode(['status' => 'leave', 'leave_type' => 'Business trip']));
        $this->assertSame('D', TimesheetWorkbook::dayCode(['status' => 'late', 'leave_type' => null]));
        $this->assertSame('F', TimesheetWorkbook::dayCode(['status' => 'holiday', 'leave_type' => null]));
        $this->assertNull(TimesheetWorkbook::dayCode(['status' => 'not_yet', 'leave_type' => null]));
    }
}
