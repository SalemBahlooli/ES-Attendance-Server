<?php

namespace Tests\Feature\Attendance;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\Punch;
use App\Models\Shift;
use App\Services\Attendance\Calculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The attendance rules, the same ones the old Node.js server used. */
class CalculatorTest extends TestCase
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

    private function punch(string $pin, string ...$times): void
    {
        Employee::ensure([$pin]);
        foreach ($times as $t) {
            Punch::create(['sn' => 'X', 'pin' => $pin, 'punch_time' => $t]);
        }
    }

    private function day(string $pin, string $date): array
    {
        return collect(app(Calculator::class)->compute($date, $date)['days'])->firstWhere('pin', $pin);
    }

    public function test_present_late_and_early_leave(): void
    {
        $this->punch('1', '2026-11-16 08:10:00', '2026-11-16 16:05:00');
        $this->punch('2', '2026-11-16 08:16:00', '2026-11-16 15:30:00');
        Employee::where('pin', '1')->update(['start_date' => '2026-11-01']);
        Employee::where('pin', '2')->update(['start_date' => '2026-11-01']);

        $a = $this->day('1', '2026-11-16');
        $this->assertSame('present', $a['status']);
        $this->assertSame(0, $a['late_minutes']);
        $this->assertSame(475, $a['worked_minutes']);

        $b = $this->day('2', '2026-11-16');
        $this->assertSame('late', $b['status']);
        $this->assertSame(16, $b['late_minutes']); // full minutes once past the grace
        $this->assertSame(30, $b['early_minutes']);
    }

    public function test_absent_leave_holiday_weekend_and_worked_day_off(): void
    {
        $this->punch('1', '2026-11-01 08:00:00', '2026-11-01 16:00:00', '2026-11-13 09:00:00', '2026-11-13 12:00:00');
        $id = Employee::where('pin', '1')->value('id');
        Leave::create(['employee_id' => $id, 'type' => 'Sick', 'start_date' => '2026-11-03', 'end_date' => '2026-11-03']);
        Holiday::create(['name' => 'National Day', 'start_date' => '2026-11-04', 'end_date' => '2026-11-04']);

        $this->assertSame('absent', $this->day('1', '2026-11-02')['status']);
        $leave = $this->day('1', '2026-11-03');
        $this->assertSame('leave', $leave['status']);
        $this->assertSame('Sick', $leave['leave_type']);
        $this->assertSame('holiday', $this->day('1', '2026-11-04')['status']);
        $this->assertSame('weekend', $this->day('1', '2026-11-06')['status']); // Friday
        $this->assertSame('offday_work', $this->day('1', '2026-11-13')['status']); // punched on a Friday
    }

    public function test_days_before_the_first_punch_or_start_date_are_not_counted(): void
    {
        $this->punch('1', '2026-11-10 08:00:00');
        $result = app(Calculator::class)->compute('2026-11-01', '2026-11-12');

        $this->assertSame('2026-11-10', collect($result['days'])->where('pin', '1')->first()['date']);
    }

    public function test_not_in_yet_today_and_no_check_out(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 05:10:00', 'UTC')); // 08:10 in Riyadh
        $this->punch('1', '2026-11-17 08:00:00', '2026-11-17 08:02:00'); // double tap only
        $this->punch('2', '2026-11-17 08:00:00');

        $this->assertSame('not_yet', $this->day('1', '2026-11-18')['status']);

        $yesterday = $this->day('1', '2026-11-17');
        $this->assertTrue($yesterday['missing_out']); // two punches 2 minutes apart are one
        $this->assertNull($yesterday['last_out']);
        $this->assertSame(0, $yesterday['worked_minutes']);
    }

    public function test_night_shift_counts_on_the_day_it_starts(): void
    {
        $night = Shift::create(['name' => 'Night', 'start_time' => '20:00', 'end_time' => '04:00', 'grace_in' => 10, 'grace_out' => 0, 'weekend' => []]);
        $dept = Department::create(['name' => 'Workshop', 'shift_id' => $night->id]);
        $this->punch('1', '2026-11-16 19:55:00', '2026-11-17 04:05:00');
        Employee::where('pin', '1')->update(['department_id' => $dept->id]);

        $day = $this->day('1', '2026-11-16');
        $this->assertSame('present', $day['status']);
        $this->assertSame('Night', $day['shift']);
        $this->assertSame('2026-11-17 04:05:00', $day['last_out']);
        $this->assertSame(490, $day['worked_minutes']);
    }

    public function test_inactive_employees_and_deleted_punches_are_left_out(): void
    {
        $this->punch('1', '2026-11-16 08:00:00', '2026-11-16 16:00:00');
        $this->punch('2', '2026-11-16 08:00:00');
        Employee::where('pin', '2')->update(['is_active' => false]);
        Punch::where('pin', '1')->where('punch_time', '2026-11-16 16:00:00')->first()->delete();

        $days = app(Calculator::class)->compute('2026-11-16', '2026-11-16')['days'];
        $this->assertCount(1, $days);
        $this->assertTrue($days[0]['missing_out']);
    }

    public function test_summary_adds_up(): void
    {
        $this->punch('1', '2026-11-16 08:20:00', '2026-11-16 16:00:00', '2026-11-17 08:00:00', '2026-11-17 16:00:00');
        $sum = app(Calculator::class)->compute('2026-11-15', '2026-11-18')['summary'][0];

        // 15th = Sunday (before the first punch, skipped), 16, 17 present, 18 absent
        $this->assertSame(3, $sum['work_days']);
        $this->assertSame(2, $sum['present']);
        $this->assertSame(1, $sum['absent']);
        $this->assertSame(1, $sum['late_count']);
        $this->assertSame(20, $sum['late_minutes']);
    }
}
