<?php

namespace Tests\Feature\Attendance;

use App\Models\Employee;
use App\Models\Leave;
use App\Models\Punch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Deleting several employees at once from the employees page. */
class DeleteEmployeesTest extends TestCase
{
    use RefreshDatabase;

    public function test_chosen_employees_are_deleted_with_their_leaves_and_their_punches_kept_as_deleted(): void
    {
        $user = $this->signInWith(['employees.view', 'employees.edit', 'employees.delete']);
        $gone = Employee::create(['pin' => '1', 'name' => 'Left the company']);
        $alsoGone = Employee::create(['pin' => '2']);
        $stays = Employee::create(['pin' => '3']);
        Punch::create(['sn' => 'X', 'pin' => '1', 'punch_time' => '2026-11-16 08:00:00']);
        Punch::create(['sn' => 'X', 'pin' => '3', 'punch_time' => '2026-11-16 08:00:00']);
        Leave::create(['employee_id' => $gone->id, 'type' => 'annual', 'start_date' => '2026-11-20', 'end_date' => '2026-11-21']);

        $this->delete(route('employees.destroy-many'), ['ids' => [$gone->id, $alsoGone->id]])
            ->assertRedirect(route('employees.index'))->assertSessionHas('success');

        $this->assertSame(['3'], Employee::pluck('pin')->all());
        $this->assertSame(0, Leave::count());
        $this->assertSame(['3'], Punch::pluck('pin')->all());
        $this->assertSame($user->id, Punch::onlyTrashed()->where('pin', '1')->value('deleted_by'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'employees_deleted']);
        // Each one with a copy of their details
        $this->assertDatabaseHas('activity_logs', ['action' => 'force_deleted', 'subject_type' => $gone->getMorphClass(), 'subject_id' => $gone->id]);
    }

    public function test_re_importing_an_old_file_does_not_bring_a_deleted_employee_back(): void
    {
        $this->signInWith(['employees.view', 'employees.edit', 'employees.delete', 'imports.manage']);
        $file = fn () => UploadedFile::fake()->createWithContent('1_attlog.dat', "1\t2026-11-16 08:00:00\t1\t0\t1\t0\n");
        $this->post(route('imports.store'), ['file' => $file()])->assertOk();

        $this->delete(route('employees.destroy-many'), ['ids' => [Employee::where('pin', '1')->value('id')]]);
        $this->post(route('imports.store'), ['file' => $file()])->assertOk()->assertJson(['inserted' => 0]);

        $this->assertFalse(Employee::where('pin', '1')->exists());

        // A new punch from the same number is a real one: the employee is back
        $this->post(route('imports.store'), ['file' => UploadedFile::fake()->createWithContent('1_attlog.dat', "1\t2026-11-17 08:00:00\t1\t0\t1\t0\n")])
            ->assertOk()->assertJson(['inserted' => 1]);
        $this->assertTrue(Employee::where('pin', '1')->exists());
    }

    public function test_the_device_list_of_enrolled_people_does_not_bring_a_deleted_employee_back(): void
    {
        $user = $this->signInWith(['employees.view', 'employees.edit', 'employees.delete', 'imports.manage']);
        $token = $user->createToken('bridge')->plainTextToken;
        Employee::create(['pin' => '1', 'name' => 'Left the company']);
        Employee::create(['pin' => '2']);
        $this->delete(route('employees.destroy-many'), ['ids' => Employee::pluck('id')->all()]);
        $this->assertDatabaseHas('removed_employees', ['pin' => '1', 'name' => 'Left the company', 'removed_by' => $user->id]);

        // ADMS user records, and the LAN bridge's user list (like a LAN pull)
        $this->call('POST', '/iclock/cdata?SN=ABC123&table=USERINFO', [], [], [], ['CONTENT_TYPE' => 'text/plain'], "PIN=1\tName=Still enrolled\nPIN=5\tName=New person\n")->assertOk();
        auth()->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/punches/import', ['records' => [], 'users' => [['pin' => '2', 'name' => 'Also enrolled']]])->assertOk();

        $this->assertSame(['5'], Employee::pluck('pin')->all(), 'Only someone never deleted is added');

        // A new punch is real: the employee is back, with the device name, and no longer remembered as deleted
        $this->withToken($token)->postJson('/api/v1/punches/import', [
            'records' => [['pin' => '1', 'time' => '2026-11-18 08:00:00']], 'users' => [['pin' => '1', 'name' => 'Still enrolled']],
        ])->assertOk();

        $this->assertSame('Still enrolled', Employee::where('pin', '1')->value('name'));
        $this->assertDatabaseMissing('removed_employees', ['pin' => '1']);
        $this->assertDatabaseHas('removed_employees', ['pin' => '2']);
    }

    public function test_deleting_needs_its_own_permission(): void
    {
        $this->signInWith(['employees.view', 'employees.edit']);
        $employee = Employee::create(['pin' => '1']);

        $this->delete(route('employees.destroy-many'), ['ids' => [$employee->id]])->assertForbidden();
        $this->get(route('employees.index'))->assertOk()->assertDontSee('employees-delete');
        $this->assertTrue($employee->exists());
    }

    public function test_the_page_offers_the_checkboxes_to_those_who_may_delete(): void
    {
        $this->signInWith(['employees.view', 'employees.edit', 'employees.delete']);
        Employee::create(['pin' => '1']);

        $this->get(route('employees.index'))->assertOk()->assertSee('employees-delete');
        $this->delete(route('employees.destroy-many'), ['ids' => []])->assertSessionHasErrors('ids');
    }
}
