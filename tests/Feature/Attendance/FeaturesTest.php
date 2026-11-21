<?php

namespace Tests\Feature\Attendance;

use App\Models\Employee;
use App\Models\EmployeeRequest;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\Punch;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\SpecialPeriod;
use App\Models\User;
use App\Services\Attendance\Calculator;
use App\Services\Attendance\LeaveBalance;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The optional features from Settings → Optional features. */
class FeaturesTest extends TestCase
{
    use RefreshDatabase;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 2026-11-18, 18:00 in Riyadh
        $this->travelTo(Carbon::parse('2026-11-18 15:00:00', 'UTC'));
        $this->shift = Shift::create(['name' => 'Standard', 'start_time' => '08:00', 'end_time' => '16:00', 'grace_in' => 15, 'grace_out' => 0, 'weekend' => [5, 6], 'is_default' => true]);
    }

    private function on(string ...$features): void
    {
        app(Settings::class)->set(collect($features)->mapWithKeys(fn ($f) => ['feature_'.$f => true])->all());
    }

    private function employee(string $pin = '1', array $attributes = []): Employee
    {
        return Employee::create(['pin' => $pin, 'name' => 'Sara', 'is_active' => true, 'start_date' => '2026-11-01'] + $attributes);
    }

    private function punch(string $pin, string ...$times): void
    {
        foreach ($times as $t) {
            Punch::create(['sn' => 'X', 'pin' => $pin, 'punch_time' => $t]);
        }
    }

    private function day(string $pin, string $date): array
    {
        return collect(app(Calculator::class)->compute($date, $date)['days'])->firstWhere('pin', $pin);
    }

    // ------------------------------------------------------------------ switches

    public function test_feature_pages_do_not_exist_while_switched_off(): void
    {
        $this->signInAsSuperUser();

        $this->get(route('portal.index'))->assertNotFound();
        $this->get(route('requests.index'))->assertNotFound();
        $this->get(route('schedules.index'))->assertNotFound();
        $this->get(route('employees.sheet', $this->employee()))->assertNotFound();
        $this->post(route('shifts.special.store'))->assertNotFound();
    }

    public function test_requests_need_the_portal_too(): void
    {
        $this->on('leave_requests');
        $this->assertFalse(feature('leave_requests'));
        $this->on('portal');
        $this->assertTrue(feature('leave_requests'));
    }

    public function test_menu_shows_feature_items_only_when_on(): void
    {
        $user = $this->signInAsSuperUser();
        $this->employee('1', ['user_id' => $user->id]);

        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('portal.index'))->assertDontSee(route('schedules.index'));

        $this->on('portal', 'rosters', 'leave_requests');
        $this->get(route('dashboard'))->assertOk()->assertSee(route('portal.index'))->assertSee(route('schedules.index'))->assertSee(route('requests.index'));
    }

    // ------------------------------------------------------------------ overtime

    public function test_overtime_after_the_shift_end_and_on_days_off(): void
    {
        $this->employee();
        $this->punch('1', '2026-11-16 08:00:00', '2026-11-16 17:10:00'); // 70 min after 16:00
        $this->punch('1', '2026-11-17 08:00:00', '2026-11-17 16:20:00'); // 20 min: under the minimum
        $this->punch('1', '2026-11-13 09:00:00', '2026-11-13 12:00:00'); // Friday

        $this->assertSame(0, $this->day('1', '2026-11-16')['overtime_minutes']); // off by default

        $this->on('overtime');
        app(Settings::class)->set(['overtime_min_minutes' => 30]);

        $this->assertSame(70, $this->day('1', '2026-11-16')['overtime_minutes']);
        $this->assertSame(0, $this->day('1', '2026-11-17')['overtime_minutes']);
        $this->assertSame(180, $this->day('1', '2026-11-13')['overtime_minutes']);

        $summary = collect(app(Calculator::class)->compute('2026-11-13', '2026-11-17')['summary'])->firstWhere('pin', '1');
        $this->assertSame(250, $summary['overtime_minutes']);
    }

    // ------------------------------------------------------------------ flexible hours

    public function test_flexible_hours_are_on_by_default_and_count_only_the_hours(): void
    {
        $this->assertTrue(feature('flexible'));
        $this->shift->update(['flexible' => true, 'required_minutes' => 480]);
        $this->employee();
        $this->punch('1', '2026-11-16 10:00:00', '2026-11-16 18:00:00'); // late start, full 8 hours
        $this->punch('1', '2026-11-17 09:00:00', '2026-11-17 16:30:00'); // 30 minutes short

        $mon = $this->day('1', '2026-11-16');
        $this->assertSame(['present', 0, 0], [$mon['status'], $mon['late_minutes'], $mon['early_minutes']]);
        $tue = $this->day('1', '2026-11-17');
        $this->assertSame(['present', 0, 30], [$tue['status'], $tue['late_minutes'], $tue['early_minutes']]);

        $this->on('overtime');
        app(Settings::class)->set(['overtime_min_minutes' => 30]);
        $this->punch('1', '2026-11-18 07:00:00', '2026-11-18 16:00:00'); // 1 hour more
        $this->assertSame(60, $this->day('1', '2026-11-18')['overtime_minutes']);

        // Switched off: the fixed times apply again
        app(Settings::class)->set(['feature_flexible' => false]);
        $this->assertSame('late', $this->day('1', '2026-11-16')['status']);
    }

    public function test_flexible_hours_are_set_on_the_shift_form(): void
    {
        $this->signInWith(['shifts.manage']);

        $this->put(route('shifts.update', $this->shift), [
            'name' => 'Standard', 'start_time' => '07:00', 'end_time' => '17:00', 'grace_in' => 0, 'grace_out' => 0,
            'weekend' => [5, 6], 'flexible' => 1, 'required_hours' => 7.5,
        ])->assertSessionHasNoErrors();
        $this->assertSame([true, 450], [$this->shift->fresh()->flexible, $this->shift->fresh()->required_minutes]);
        $this->get(route('shifts.index'))->assertOk()->assertSee(__('Flexible: :h a day', ['h' => '7:30']));
    }

    // ------------------------------------------------------------------ shift schedules

    public function test_a_shift_assignment_replaces_the_shift_on_its_days(): void
    {
        $night = Shift::create(['name' => 'Night', 'start_time' => '20:00', 'end_time' => '04:00', 'grace_in' => 15, 'grace_out' => 0, 'weekend' => [5, 6]]);
        $e = $this->employee();
        ShiftAssignment::create(['employee_id' => $e->id, 'shift_id' => $night->id, 'start_date' => '2026-11-16', 'end_date' => '2026-11-16']);
        $this->punch('1', '2026-11-16 20:30:00', '2026-11-17 04:40:00', '2026-11-17 08:00:00', '2026-11-17 16:00:00');

        // Off: the normal shift, so 20:30 is a very late check-in
        $this->assertSame('Standard', $this->day('1', '2026-11-16')['shift']);

        $this->on('rosters');
        $mon = $this->day('1', '2026-11-16');
        $this->assertSame('Night', $mon['shift']);
        $this->assertSame('late', $mon['status']);
        $this->assertSame(30, $mon['late_minutes']);
        $this->assertSame('2026-11-17 04:40:00', $mon['last_out']);

        // The next day is back on the normal shift and keeps its own punches
        $tue = $this->day('1', '2026-11-17');
        $this->assertSame('Standard', $tue['shift']);
        $this->assertSame('present', $tue['status']);
        $this->assertSame('2026-11-17 08:00:00', $tue['first_in']);
    }

    public function test_rotation_creates_alternating_blocks(): void
    {
        $this->on('rosters');
        $night = Shift::create(['name' => 'Night', 'start_time' => '20:00', 'end_time' => '04:00', 'grace_in' => 15, 'grace_out' => 0, 'weekend' => [5, 6]]);
        $e = $this->employee();
        $this->signInWith(['shifts.manage']);

        $this->post(route('schedules.store'), [
            'employee_id' => $e->id, 'shift_id' => $this->shift->id, 'alt_shift_id' => $night->id, 'rotate_weeks' => 1,
            'start_date' => '2026-12-01', 'end_date' => '2026-12-28',
        ])->assertSessionHasNoErrors();

        $rows = ShiftAssignment::orderBy('start_date')->get();
        $this->assertCount(4, $rows);
        $this->assertSame([$this->shift->id, $night->id, $this->shift->id, $night->id], $rows->pluck('shift_id')->all());
        $this->assertSame('2026-12-07', $rows[0]->end_date->toDateString());
        $this->assertSame('2026-12-28', $rows[3]->end_date->toDateString());

        $this->get(route('schedules.index'))->assertOk()->assertSee('Night');
    }

    public function test_schedules_need_the_permission(): void
    {
        $this->on('rosters', 'special_hours');
        $this->signInWith(['attendance.view']);

        $this->get(route('schedules.index'))->assertForbidden();
        $this->post(route('schedules.store'))->assertForbidden();
        $this->post(route('shifts.special.store'))->assertForbidden();
    }

    // ------------------------------------------------------------------ Ramadan hours

    public function test_special_hours_change_the_times_between_two_dates(): void
    {
        $this->employee();
        SpecialPeriod::create(['name' => 'Ramadan', 'start_date' => '2026-11-16', 'end_date' => '2026-11-16', 'start_time' => '10:00', 'end_time' => '15:00']);
        $this->punch('1', '2026-11-16 10:05:00', '2026-11-16 15:00:00');

        $this->assertSame('late', $this->day('1', '2026-11-16')['status']); // off: 08:00 start

        $this->on('special_hours');
        $d = $this->day('1', '2026-11-16');
        $this->assertSame('present', $d['status']);
        $this->assertSame(0, $d['early_minutes']);
        $this->assertSame('Ramadan', $d['special']);
        $this->assertSame('10:00', $d['shift_start']);
    }

    public function test_special_hours_can_be_added_on_the_shifts_page(): void
    {
        $this->on('special_hours');
        $this->signInWith(['shifts.manage']);

        $this->post(route('shifts.special.store'), [
            'name' => 'Ramadan', 'start_date' => '2027-02-08', 'end_date' => '2027-03-09', 'start_time' => '09:00', 'end_time' => '14:00',
        ])->assertSessionHasNoErrors();
        $this->get(route('shifts.index'))->assertOk()->assertSee('Ramadan');

        $this->delete(route('shifts.special.destroy', SpecialPeriod::first()))->assertRedirect();
        $this->assertDatabaseCount('special_periods', 0);
    }

    // ------------------------------------------------------------------ leave balances

    public function test_leave_balance_counts_work_days_of_annual_leaves(): void
    {
        $e = $this->employee('1', ['annual_leave_days' => 21]);
        // Sun 15 – Sat 21 November: 5 work days, minus a public holiday on Tuesday
        Leave::create(['employee_id' => $e->id, 'type' => 'Annual', 'start_date' => '2026-11-15', 'end_date' => '2026-11-21']);
        Holiday::create(['name' => 'Holiday', 'start_date' => '2026-11-17', 'end_date' => '2026-11-17']);
        Leave::create(['employee_id' => $e->id, 'type' => 'Sick', 'start_date' => '2026-11-22', 'end_date' => '2026-11-23']);

        $b = LeaveBalance::for($e, 2026);
        $this->assertSame(21, $b['entitlement']);
        $this->assertSame(4, $b['used']);
        $this->assertSame(17, $b['remaining']);

        $e->update(['annual_leave_days' => null]);
        app(Settings::class)->set(['annual_leave_days' => 30]);
        $this->assertSame(26, LeaveBalance::for($e->fresh(), 2026)['remaining']);
    }

    // ------------------------------------------------------------------ portal and requests

    public function test_the_portal_shows_only_the_linked_employee(): void
    {
        $this->on('portal');
        $user = $this->signInWith([]);
        $this->employee('1', ['user_id' => $user->id, 'name' => 'Sara']);
        $this->employee('2', ['name' => 'Huda']);
        $this->punch('1', '2026-11-16 08:00:00', '2026-11-16 16:00:00');

        $this->get(route('portal.index', ['from' => '2026-11-16', 'to' => '2026-11-16']))
            ->assertOk()->assertSee('Sara')->assertDontSee('Huda');
    }

    public function test_an_account_without_employee_sees_a_message(): void
    {
        $this->on('portal');
        $this->signInWith([]);

        $this->get(route('portal.index'))->assertOk()->assertSee(__('Your account is not linked to an employee'));
    }

    public function test_leave_request_flow(): void
    {
        $this->on('portal', 'leave_requests', 'leave_balances');
        $employeeUser = User::factory()->create();
        $e = $this->employee('1', ['user_id' => $employeeUser->id]);
        $reviewer = User::factory()->withPermissions(['requests.review' => 1])->create();

        $this->actingAs($employeeUser)->post(route('portal.requests.store'), [
            'kind' => 'leave', 'leave_type' => 'Annual', 'start_date' => '2026-11-22', 'end_date' => '2026-11-23', 'reason' => 'Family',
        ])->assertSessionHasNoErrors();
        $req = EmployeeRequest::sole();
        $this->assertSame('pending', $req->status);
        $this->assertSame(1, $reviewer->notifications()->count());
        $this->assertSame(2, LeaveBalance::for($e, 2026)['pending']);

        // The same days again: refused
        $this->post(route('portal.requests.store'), ['kind' => 'leave', 'leave_type' => 'Sick', 'start_date' => '2026-11-23', 'end_date' => '2026-11-23'])
            ->assertSessionHasErrors('start_date');

        $this->actingAs($reviewer)->get(route('requests.index'))->assertOk()->assertSee('Family');
        $this->post(route('requests.approve', $req))->assertRedirect();

        $this->assertSame('approved', $req->fresh()->status);
        $leave = Leave::sole();
        $this->assertSame([$e->id, 'Annual', '2026-11-22', '2026-11-23', 'request'], [$leave->employee_id, $leave->type, $leave->start_date->toDateString(), $leave->end_date->toDateString(), $leave->source]);
        $this->assertSame(1, $employeeUser->notifications()->count());

        // Reviewed once only
        $this->post(route('requests.reject', $req))->assertStatus(422);
    }

    public function test_punch_request_flow_adds_a_manual_punch(): void
    {
        $this->on('portal', 'punch_requests');
        $employeeUser = User::factory()->create();
        $this->employee('1', ['user_id' => $employeeUser->id]);
        $this->punch('1', '2026-11-16 08:00:00');

        $this->actingAs($employeeUser)->post(route('portal.requests.store'), ['kind' => 'punch', 'punch_time' => '2026-11-16T16:02', 'reason' => 'Forgot'])
            ->assertSessionHasNoErrors();
        // In the future: refused
        $this->post(route('portal.requests.store'), ['kind' => 'punch', 'punch_time' => '2026-11-30T16:00', 'reason' => 'x'])
            ->assertSessionHasErrors('punch_time');
        // Leave requests are off
        $this->post(route('portal.requests.store'), ['kind' => 'leave', 'leave_type' => 'Annual', 'start_date' => '2026-11-22', 'end_date' => '2026-11-22'])
            ->assertSessionHasErrors('kind');

        $req = EmployeeRequest::sole();
        $this->actingAs(User::factory()->withPermissions(['requests.review' => 1])->create())
            ->post(route('requests.approve', $req), ['review_note' => 'OK'])->assertRedirect();

        $punch = Punch::where('punch_time', '2026-11-16 16:02:00')->sole();
        $this->assertSame('manual', $punch->source);
        $this->assertSame($punch->id, $req->fresh()->result_id);
        $this->assertSame('2026-11-16 16:02:00', $this->day('1', '2026-11-16')['last_out']);
    }

    public function test_nobody_reviews_their_own_request_and_others_cannot_cancel_it(): void
    {
        $this->on('portal', 'leave_requests');
        $user = User::factory()->withPermissions(['requests.review' => 1])->create();
        $e = $this->employee('1', ['user_id' => $user->id]);
        $req = EmployeeRequest::create(['employee_id' => $e->id, 'user_id' => $user->id, 'kind' => 'leave', 'leave_type' => 'Annual', 'start_date' => '2026-11-22', 'end_date' => '2026-11-22']);

        $this->actingAs($user)->post(route('requests.approve', $req))->assertForbidden();

        $other = User::factory()->create();
        $this->employee('2', ['user_id' => $other->id]);
        $this->actingAs($other)->delete(route('portal.requests.cancel', $req))->assertNotFound();

        $this->actingAs($user)->delete(route('portal.requests.cancel', $req))->assertRedirect();
        $this->assertSame('cancelled', $req->fresh()->status);
    }

    public function test_reviewing_needs_the_permission(): void
    {
        $this->on('portal', 'leave_requests');
        $this->signInWith(['leaves.view']);
        $e = $this->employee();
        $req = EmployeeRequest::create(['employee_id' => $e->id, 'kind' => 'leave', 'leave_type' => 'Annual', 'start_date' => '2026-11-22', 'end_date' => '2026-11-22']);

        $this->get(route('requests.index'))->assertForbidden();
        $this->post(route('requests.approve', $req))->assertForbidden();
        $this->post(route('requests.reject', $req))->assertForbidden();
    }

    public function test_linking_an_account_on_the_employee_page(): void
    {
        $this->on('portal', 'leave_balances');
        $this->signInWith(['employees.view', 'employees.edit']);
        $e = $this->employee();
        $account = User::factory()->create();

        $this->get(route('employees.show', $e))->assertOk()->assertSee($account->email);
        $this->put(route('employees.update', $e), ['name' => 'Sara', 'is_active' => 1, 'user_id' => $account->id, 'annual_leave_days' => 25])
            ->assertSessionHasNoErrors();
        $this->assertSame([$account->id, 25], [$e->fresh()->user_id, $e->fresh()->annual_leave_days]);

        // One account, one employee
        $other = $this->employee('2');
        $this->put(route('employees.update', $other), ['is_active' => 1, 'user_id' => $account->id])->assertSessionHasErrors('user_id');
    }

    // ------------------------------------------------------------------ printable sheet

    public function test_printable_sheet(): void
    {
        $this->on('employee_sheet', 'portal');
        $user = $this->signInWith(['employees.view']);
        $e = $this->employee('1', ['name' => 'Sara']);
        $this->punch('1', '2026-11-16 08:00:00', '2026-11-16 16:00:00');

        $this->get(route('employees.sheet', ['employee' => $e, 'from' => '2026-11-01', 'to' => '2026-11-18']))
            ->assertOk()->assertSee(__('Attendance sheet'))->assertSee('Sara')->assertSee(__('Direct manager'));

        // Their own sheet from the portal
        $e->update(['user_id' => $user->id]);
        $this->get(route('portal.sheet'))->assertOk()->assertSee('Sara');
    }

    public function test_printable_sheet_needs_the_permission(): void
    {
        $this->on('employee_sheet');
        $this->signInWith(['attendance.view']);

        $this->get(route('employees.sheet', $this->employee()))->assertForbidden();
    }
}
