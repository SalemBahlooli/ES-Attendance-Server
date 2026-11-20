<?php

namespace Tests\Feature\Attendance;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\Punch;
use App\Models\Shift;
use App\Services\Attendance\BranchTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Sending attendance to another branch and importing it there. */
class BranchTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Shift::create(['name' => 'Standard', 'start_time' => '08:00', 'end_time' => '16:00', 'grace_in' => 15, 'grace_out' => 0, 'weekend' => [5, 6], 'is_default' => true]);
    }

    private function package(array $punches, array $leaves = []): array
    {
        return [
            'type' => BranchTransfer::TYPE, 'version' => 1, 'branch' => ['name' => 'Jeddah'],
            'period' => ['from' => '2026-11-01', 'to' => '2026-11-30'],
            'employees' => [
                ['pin' => '100', 'name' => 'Ahmed', 'department' => 'Sales', 'start_date' => '2026-01-01',
                    'shift' => ['name' => 'Early', 'start' => '07:00', 'end' => '15:00', 'grace_in' => 10, 'grace_out' => 0, 'weekend' => [5]]],
            ],
            'punches' => $punches,
            'leaves' => $leaves,
        ];
    }

    private function upload(array $pkg, string $route = 'imports.store')
    {
        return $this->post(route($route), ['file' => UploadedFile::fake()->createWithContent('Attendance_Jeddah.json', json_encode($pkg))]);
    }

    public function test_export_holds_only_the_current_data(): void
    {
        $this->signInWith(['reports.view', 'reports.export']);
        Employee::create(['pin' => '1', 'name' => 'Saad']);
        Punch::create(['sn' => 'X', 'pin' => '1', 'punch_time' => '2026-11-16 08:00:00']);
        Punch::create(['sn' => 'MANUAL', 'pin' => '1', 'punch_time' => '2026-11-16 16:00:00', 'source' => 'manual']);
        Punch::create(['sn' => 'X', 'pin' => '1', 'punch_time' => '2026-11-17 08:00:00'])->delete();

        $response = $this->get(route('reports.branch-file', ['from' => '2026-11-01', 'to' => '2026-11-30', 'branch' => 'Riyadh HQ']))->assertOk();
        $this->assertStringContainsString('Attendance_Riyadh_HQ_2026-11-01_2026-11-30.json', $response->headers->get('Content-Disposition'));

        $pkg = json_decode($response->getContent(), true);
        $this->assertSame(BranchTransfer::TYPE, $pkg['type']);
        $this->assertSame([['pin' => '1', 'time' => '2026-11-16 08:00:00', 'manual' => false], ['pin' => '1', 'time' => '2026-11-16 16:00:00', 'manual' => true]], $pkg['punches']);
        $this->assertSame('08:00', $pkg['employees'][0]['shift']['start']);
        $this->assertSame('Riyadh HQ', setting('branch_name'));
    }

    public function test_import_adds_employees_punches_and_leaves_then_follows_newer_files(): void
    {
        $this->signInWith(['imports.manage']);
        $first = $this->package(
            [['pin' => '100', 'time' => '2026-11-16 07:00:00', 'manual' => false], ['pin' => '100', 'time' => '2026-11-16 15:00:00', 'manual' => true]],
            [['pin' => '100', 'type' => 'Annual', 'start_date' => '2026-11-20', 'end_date' => '2026-11-22', 'note' => null]],
        );

        $this->upload($first, 'imports.preview')->assertOk()->assertJson(['kind' => 'branch', 'punches' => 2, 'new_punches' => 2, 'removed' => 0, 'leaves' => 1]);
        $this->upload($first)->assertOk()->assertJson(['inserted' => 2, 'new_employees' => 1, 'leaves' => 1]);

        $employee = Employee::where('pin', '100')->first();
        $this->assertSame('Ahmed', $employee->name);
        $this->assertSame('Sales', $employee->department->name);
        $this->assertSame('Jeddah - Early', $employee->shift->name);
        $this->assertSame('manual', Punch::where('punch_time', '2026-11-16 15:00:00')->value('source'));
        $this->assertSame(1, Leave::count());

        // The same file again changes nothing
        $this->upload($first)->assertOk()->assertJson(['inserted' => 0, 'removed' => 0, 'leaves' => 0]);

        // A newer file: the check-out was corrected there, the leave was cancelled
        $second = $this->package([['pin' => '100', 'time' => '2026-11-16 07:00:00', 'manual' => false], ['pin' => '100', 'time' => '2026-11-16 15:30:00', 'manual' => true]]);
        $this->upload($second)->assertOk()->assertJson(['inserted' => 1, 'removed' => 1]);
        $this->assertSame(['2026-11-16 07:00:00', '2026-11-16 15:30:00'], Punch::orderBy('punch_time')->get()->map->time()->all());
        $this->assertSame(0, Leave::count());

        // And back: the first file brings the removed punch back
        $this->upload($first)->assertOk()->assertJson(['removed' => 1]);
        $this->assertTrue(Punch::where('punch_time', '2026-11-16 15:00:00')->exists());
    }

    public function test_import_keeps_local_details_and_local_deletions(): void
    {
        $this->signInWith(['imports.manage']);
        $dept = Department::create(['name' => 'Local']);
        Employee::create(['pin' => '100', 'name' => 'Local name', 'department_id' => $dept->id]);
        $pkg = $this->package([['pin' => '100', 'time' => '2026-11-16 07:00:00', 'manual' => false]]);
        $this->upload($pkg)->assertOk();

        // Someone here deletes the imported punch: the next file does not bring it back
        Punch::first()->delete();
        $this->upload($pkg)->assertOk()->assertJson(['inserted' => 0]);

        $employee = Employee::where('pin', '100')->first();
        $this->assertSame('Local name', $employee->name);
        $this->assertSame($dept->id, $employee->department_id);
        $this->assertSame(0, Punch::count());
    }

    public function test_wrong_files_are_refused(): void
    {
        $this->signInWith(['imports.manage']);

        $this->upload(['type' => 'something-else'])->assertStatus(422);
        $this->upload([...$this->package([]), 'version' => 99])->assertStatus(422);
        $this->post(route('imports.store'), ['file' => UploadedFile::fake()->createWithContent('x.json', '{not json')])->assertStatus(422);
    }
}
