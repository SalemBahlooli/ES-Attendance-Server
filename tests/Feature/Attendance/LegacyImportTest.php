<?php

namespace Tests\Feature\Attendance;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Group;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\Punch;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\AttendanceSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** php artisan attendance:import-legacy, against a copy of the old Node.js schema. */
class LegacyImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AttendanceSeeder::class);
        Group::firstOrCreate(['name' => 'Administrators'], ['permissions' => ['superuser' => 1]]);

        config(['database.connections.old' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        $s = Schema::connection('old');
        $s->create('settings', fn (Blueprint $t) => [$t->string('key'), $t->text('value')->nullable()]);
        $s->create('shifts', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('start_time');
            $t->string('end_time');
            $t->integer('grace_in');
            $t->integer('grace_out');
            $t->string('weekend');
            $t->boolean('is_default');
        });
        $s->create('departments', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->integer('shift_id')->nullable()]);
        $s->create('employees', function (Blueprint $t) {
            $t->string('pin');
            $t->string('name')->nullable();
            $t->integer('department_id')->nullable();
            $t->integer('shift_id')->nullable();
            $t->boolean('active')->default(true);
            $t->string('start_date')->nullable();
            $t->string('created_at')->nullable();
        });
        $s->create('devices', function (Blueprint $t) {
            $t->string('sn');
            foreach (['name', 'ip', 'info', 'first_seen', 'last_seen', 'lan_ip'] as $c) {
                $t->string($c)->nullable();
            }
            $t->integer('lan_port')->nullable();
        });
        $s->create('attendance', function (Blueprint $t) {
            $t->id();
            $t->string('sn');
            $t->string('pin');
            $t->string('punch_time');
            $t->integer('status')->nullable();
            $t->integer('verify')->nullable();
            $t->text('raw')->nullable();
            $t->string('received_at')->nullable();
            $t->string('source')->nullable();
            $t->string('deleted_at')->nullable();
            $t->string('deleted_by')->nullable();
            $t->string('created_by')->nullable();
            $t->string('note')->nullable();
            $t->integer('replaces_id')->nullable();
        });
        $s->create('holidays', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->string('start_date'), $t->string('end_date')]);
        $s->create('leaves', function (Blueprint $t) {
            $t->id();
            $t->string('pin');
            $t->string('type');
            $t->string('start_date');
            $t->string('end_date');
            $t->string('note')->nullable();
            $t->string('created_at')->nullable();
            $t->string('source')->nullable();
        });
        $s->create('roles', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->text('permissions'), $t->boolean('is_system')]);
        $s->create('users', function (Blueprint $t) {
            $t->id();
            $t->string('username');
            $t->string('name')->nullable();
            $t->string('password_hash');
            $t->integer('role_id')->nullable();
            $t->boolean('active')->default(true);
            $t->string('last_login')->nullable();
            $t->string('created_at')->nullable();
        });
        $s->create('imports', function (Blueprint $t) {
            $t->id();
            $t->string('source');
            $t->string('label')->nullable();
            $t->string('sn')->nullable();
            foreach (['total', 'inserted', 'duplicates', 'names'] as $c) {
                $t->integer($c)->default(0);
            }
            $t->string('username')->nullable();
            $t->string('created_at')->nullable();
        });
        $s->create('audit_log', function (Blueprint $t) {
            $t->id();
            $t->integer('user_id')->nullable();
            $t->string('username')->nullable();
            $t->string('action');
            $t->text('details')->nullable();
            $t->string('ip')->nullable();
            $t->string('created_at')->nullable();
        });

        $old = DB::connection('old');
        $old->table('settings')->insert(['key' => 'branch_name', 'value' => 'Dammam']);
        $old->table('shifts')->insert([
            ['id' => 1, 'name' => 'Standard shift', 'start_time' => '07:00:00', 'end_time' => '15:00:00', 'grace_in' => 10, 'grace_out' => 5, 'weekend' => '{5,6}', 'is_default' => true],
            ['id' => 2, 'name' => 'Night', 'start_time' => '20:00:00', 'end_time' => '04:00:00', 'grace_in' => 15, 'grace_out' => 0, 'weekend' => '{5}', 'is_default' => false],
        ]);
        $old->table('departments')->insert(['id' => 7, 'name' => 'Workshop', 'shift_id' => 2]);
        $old->table('employees')->insert([
            ['pin' => '12', 'name' => 'Ahmed', 'department_id' => 7, 'shift_id' => null, 'active' => true, 'start_date' => '2026-01-01'],
            ['pin' => '13', 'name' => 'Huda', 'department_id' => null, 'shift_id' => null, 'active' => false, 'start_date' => null],
        ]);
        $old->table('devices')->insert(['sn' => 'ABC123', 'name' => 'Gate', 'first_seen' => '2026-01-01 08:00:00+03', 'last_seen' => '2026-11-01 08:00:00+03', 'lan_ip' => '192.168.1.20', 'lan_port' => 4370]);
        $old->table('attendance')->insert([
            ['id' => 1, 'sn' => 'ABC123', 'pin' => '12', 'punch_time' => '2026-11-01 07:05:00', 'received_at' => '2026-11-01 07:05:10+03', 'source' => 'adms', 'deleted_at' => null, 'created_by' => null, 'note' => null, 'replaces_id' => null],
            ['id' => 2, 'sn' => 'ABC123', 'pin' => '12', 'punch_time' => '2026-11-01 15:10:00', 'received_at' => null, 'source' => 'adms', 'deleted_at' => '2026-11-02 09:00:00+03', 'created_by' => null, 'note' => 'wrong', 'replaces_id' => null],
            ['id' => 3, 'sn' => 'MANUAL', 'pin' => '12', 'punch_time' => '2026-11-01 15:00:00', 'received_at' => null, 'source' => 'manual', 'deleted_at' => null, 'created_by' => 'sara', 'note' => 'fixed', 'replaces_id' => 2],
            // The same second from a second device: one punch here
            ['id' => 4, 'sn' => 'OTHER', 'pin' => '12', 'punch_time' => '2026-11-01 07:05:00', 'received_at' => null, 'source' => 'adms', 'deleted_at' => null, 'created_by' => null, 'note' => null, 'replaces_id' => null],
            // An employee the employees table never had
            ['id' => 5, 'sn' => 'ABC123', 'pin' => '99', 'punch_time' => '2026-11-02 07:00:00', 'received_at' => null, 'source' => 'file', 'deleted_at' => null, 'created_by' => null, 'note' => null, 'replaces_id' => null],
        ]);
        $old->table('holidays')->insert(['id' => 1, 'name' => 'National Day', 'start_date' => '2026-09-23', 'end_date' => '2026-09-23']);
        $old->table('leaves')->insert([
            ['id' => 1, 'pin' => '12', 'type' => 'Annual', 'start_date' => '2026-10-01', 'end_date' => '2026-10-05', 'note' => 'Trip', 'created_at' => null, 'source' => null],
            ['id' => 2, 'pin' => '12', 'type' => 'مرضية', 'start_date' => '2026-10-10', 'end_date' => '2026-10-10', 'note' => null, 'created_at' => null, 'source' => null],
        ]);
        $old->table('roles')->insert([
            ['id' => 1, 'name' => 'System admin', 'permissions' => '{}', 'is_system' => true],
            ['id' => 2, 'name' => 'Supervisor', 'permissions' => '{attendance.view,report.view}', 'is_system' => false],
            ['id' => 3, 'name' => 'Gate keepers', 'permissions' => '{attendance.view,data.import}', 'is_system' => false],
        ]);
        $old->table('users')->insert([
            ['id' => 1, 'username' => 'sara', 'name' => 'Sara', 'password_hash' => 'x:y', 'role_id' => 1, 'active' => true],
            ['id' => 2, 'username' => 'omar@example.com', 'name' => 'Omar', 'password_hash' => 'x:y', 'role_id' => 3, 'active' => true],
        ]);
        $old->table('imports')->insert(['id' => 1, 'source' => 'file', 'label' => 'attlog.dat', 'total' => 10, 'inserted' => 8, 'duplicates' => 2, 'names' => 0, 'username' => 'sara', 'created_at' => '2026-11-01 10:00:00+03']);
        $old->table('audit_log')->insert(['id' => 1, 'username' => 'sara', 'action' => 'punch.delete', 'details' => 'Deleted 15:10', 'ip' => '10.0.0.1', 'created_at' => '2026-11-02 09:00:00+03']);
    }

    public function test_it_copies_everything_and_can_run_again(): void
    {
        $this->artisan('attendance:import-legacy', ['--connection' => 'old'])->assertSuccessful();

        $this->assertSame('Dammam', setting('branch_name'));

        // The seeded default shift takes the old times; the other shift is added
        $standard = Shift::where('name', 'Standard shift')->sole();
        $this->assertSame(['07:00', '15:00', 10, 5, true], [$standard->start(), $standard->end(), $standard->grace_in, $standard->grace_out, $standard->is_default]);
        $night = Shift::where('name', 'Night')->sole();
        $this->assertSame([5], $night->weekendDays());
        $this->assertSame($night->id, Department::where('name', 'Workshop')->value('shift_id'));

        $ahmed = Employee::where('pin', '12')->sole();
        $this->assertSame(['Ahmed', 'Workshop', '2026-01-01', true], [$ahmed->name, $ahmed->department->name, $ahmed->start_date->toDateString(), $ahmed->is_active]);
        $this->assertFalse(Employee::where('pin', '13')->first()->is_active);
        $this->assertTrue(Employee::where('pin', '99')->exists());

        $device = Device::where('sn', 'ABC123')->sole();
        $this->assertSame(['Gate', '192.168.1.20', '2026-01-01 05:00:00'], [$device->name, $device->lan_ip, $device->first_seen_at->format('Y-m-d H:i:s')]);

        // Punches: one per second, the deleted one stays deleted, the manual one points at it
        $this->assertSame(4, Punch::withTrashed()->count());
        $this->assertSame(3, Punch::count());
        $deleted = Punch::onlyTrashed()->sole();
        $this->assertSame('2026-11-01 15:10:00', $deleted->time());
        $manual = Punch::where('source', 'manual')->sole();
        $this->assertSame($deleted->id, $manual->replaces_id);
        $sara = User::where('username', 'sara')->sole();
        $this->assertSame($sara->id, $manual->created_by);
        $this->assertSame('2026-11-01 04:05:10', Punch::where('punch_time', '2026-11-01 07:05:00')->toBase()->value('received_at'));

        // Accounts and groups
        $this->assertSame('sara@legacy.local', $sara->email);
        $this->assertTrue($sara->isSuperUser());
        $omar = User::where('email', 'omar@example.com')->sole();
        $this->assertTrue($omar->hasAccess('imports.manage'));
        $this->assertFalse($omar->hasAccess('punches.edit'));
        $this->assertSame(['attendance.view' => 1, 'dashboard.view' => 1, 'imports.manage' => 1], Group::where('name', 'Gate keepers')->sole()->permissions);
        $this->assertSame(1, Group::where('name', 'Supervisors')->count()); // the existing group, not a copy

        $this->assertSame(1, Holiday::count());
        $this->assertSame(['Annual', 'Sick'], Leave::orderBy('start_date')->pluck('type')->all());
        $this->assertDatabaseHas('punch_imports', ['label' => 'attlog.dat', 'inserted' => 8, 'user_id' => $sara->id]);
        $this->assertSame(1, ActivityLog::where('action', 'legacy.punch.delete')->count());

        // Again: nothing new
        $before = [Punch::withTrashed()->count(), Employee::count(), Leave::count(), User::count(), Group::count(), ActivityLog::where('action', 'like', 'legacy.%')->count(), DB::table('punch_imports')->count()];
        $this->artisan('attendance:import-legacy', ['--connection' => 'old'])->assertSuccessful();
        $this->assertSame($before, [Punch::withTrashed()->count(), Employee::count(), Leave::count(), User::count(), Group::count(), ActivityLog::where('action', 'like', 'legacy.%')->count(), DB::table('punch_imports')->count()]);

        // A new punch in the old system comes over on the next run
        DB::connection('old')->table('attendance')->insert(['id' => 6, 'sn' => 'ABC123', 'pin' => '12', 'punch_time' => '2026-11-03 07:00:00', 'source' => 'adms']);
        $this->artisan('attendance:import-legacy', ['--connection' => 'old'])->assertSuccessful();
        $this->assertSame(5, Punch::withTrashed()->count());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->artisan('attendance:import-legacy', ['--connection' => 'old', '--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Punch::withTrashed()->count());
        $this->assertSame(0, Employee::count());
        $this->assertSame('08:00', Shift::where('name', 'Standard shift')->sole()->start());
        $this->assertSame('', (string) setting('branch_name'));
    }

    public function test_it_refuses_a_database_that_is_not_the_old_system(): void
    {
        config(['database.connections.empty' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

        $this->artisan('attendance:import-legacy', ['--connection' => 'empty'])->assertFailed();
    }
}
