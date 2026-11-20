<?php

namespace Tests\Feature\Permissions;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/** The rules in User::hasAccess(): own value > group deny > group allow, parent deny closes children. */
class PermissionRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_nothing_is_allowed_by_default(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->hasAccess('users.view'));
        $this->assertFalse($user->isSuperUser());
    }

    public function test_a_direct_permission(): void
    {
        $user = User::factory()->withPermissions(['users.view' => 1])->create();

        $this->assertTrue($user->hasAccess('users.view'));
        $this->assertFalse($user->hasAccess('users.edit'));
    }

    public function test_a_group_permission(): void
    {
        $user = User::factory()->create();
        $user->groups()->attach(Group::factory()->withPermissions(['users.view' => 1])->create());

        $this->assertTrue($user->fresh()->hasAccess('users.view'));
    }

    public function test_a_deny_in_one_group_beats_an_allow_in_another(): void
    {
        $user = User::factory()->create();
        $user->groups()->attach(Group::factory()->withPermissions(['users.view' => 1])->create());
        $user->groups()->attach(Group::factory()->withPermissions(['users.view' => -1])->create());

        $this->assertFalse($user->fresh()->hasAccess('users.view'));
    }

    public function test_the_users_own_value_beats_the_groups(): void
    {
        $user = User::factory()->withPermissions(['users.view' => 1])->create();
        $user->groups()->attach(Group::factory()->withPermissions(['users.view' => -1])->create());
        $this->assertTrue($user->fresh()->hasAccess('users.view'));

        $user->forceFill(['permissions' => ['users.view' => -1]])->save();
        $user->groups()->sync([Group::factory()->withPermissions(['users.view' => 1])->create()->id]);
        $this->assertFalse($user->fresh()->hasAccess('users.view'));
    }

    public function test_a_denied_parent_closes_its_children(): void
    {
        $user = User::factory()->withPermissions(['users.view' => -1, 'users.delete' => 1])->create();

        $this->assertFalse($user->hasAccess('users.delete'));
    }

    public function test_a_super_user_can_do_everything(): void
    {
        $user = User::factory()->superUser()->create();

        $this->assertTrue($user->hasAccess('users.delete'));
        $this->assertTrue($user->hasAccess('settings.backups'));
        $this->assertFalse($user->explicitlyGranted('users.delete'));
    }

    public function test_a_turned_off_account_can_do_nothing(): void
    {
        $user = User::factory()->superUser()->inactive()->create();

        $this->assertFalse($user->hasAccess('users.view'));
    }

    public function test_tree_permissions_are_gate_abilities(): void
    {
        $user = User::factory()->withPermissions(['users.view' => 1])->create();

        $this->assertTrue(Gate::forUser($user)->allows('users.view'));
        $this->assertTrue(Gate::forUser($user)->denies('users.delete'));
    }

    public function test_routes_check_permissions(): void
    {
        $this->signInWith();
        $this->get(route('users.index'))->assertForbidden();

        $this->signInWith(['users.view']);
        $this->get(route('users.index'))->assertOk();
        $this->get(route('users.create'))->assertForbidden();
    }

    public function test_the_menu_only_shows_what_the_person_can_open(): void
    {
        $this->signInWith(['users.view']);

        $this->get('/')->assertSee(route('users.index'))->assertDontSee(route('settings.index'));
    }

    public function test_the_permissions_command_passes(): void
    {
        $this->artisan('permissions:check')->assertSuccessful();
    }
}
