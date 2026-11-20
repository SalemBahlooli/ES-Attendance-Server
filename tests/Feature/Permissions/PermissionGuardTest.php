<?php

namespace Tests\Feature\Permissions;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Nobody can hand out more access than they hold. */
class PermissionGuardTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return $this->signInWith(['users.view', 'users.edit', 'users.permissions', 'groups.view', 'groups.create', 'groups.edit']);
    }

    public function test_a_manager_cannot_grant_super_user_or_permissions_they_lack(): void
    {
        $this->manager();
        $target = User::factory()->create();

        $this->put(route('users.access.update', $target), ['permissions' => [
            'superuser' => 1, 'settings.manage' => 1, 'users.view' => 1,
        ]])->assertRedirect();

        $map = $target->fresh()->permissionMap();
        $this->assertSame(['users.view' => 1], $map);
    }

    public function test_nobody_changes_their_own_access(): void
    {
        $me = $this->manager();

        $this->put(route('users.access.update', $me), ['permissions' => ['users.delete' => 1]])->assertSessionHas('error');
        $this->assertFalse($me->fresh()->hasAccess('users.delete'));
    }

    public function test_only_a_super_user_changes_a_super_users_access(): void
    {
        $this->manager();
        $admin = User::factory()->superUser()->create();

        $this->put(route('users.access.update', $admin), ['permissions' => ['superuser' => -1]])->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->isSuperUser());
    }

    public function test_a_manager_can_only_add_people_to_groups_within_reach(): void
    {
        $this->manager();
        $target = User::factory()->create();
        $staff = Group::factory()->withPermissions(['users.view' => 1])->create();
        $admins = Group::factory()->withPermissions(['superuser' => 1])->create();

        $this->put(route('users.access.update', $target), ['groups' => [$staff->id, $admins->id]]);

        $this->assertSame([$staff->id], $target->fresh()->groups->pluck('id')->all());
    }

    public function test_groups_out_of_reach_are_kept_when_a_manager_saves(): void
    {
        $this->manager();
        $admins = Group::factory()->withPermissions(['superuser' => 1])->create();
        $target = User::factory()->create();
        $target->groups()->attach($admins);

        $this->put(route('users.access.update', $target), ['groups' => []]);

        // The target is a super user now: a manager can't touch them at all.
        $this->assertTrue($target->fresh()->groups->contains($admins));
    }

    public function test_a_manager_cannot_put_more_into_a_group_than_they_hold(): void
    {
        $this->manager();

        $this->post(route('groups.store'), ['name' => 'Sneaky', 'permissions' => ['users.view' => 1, 'settings.manage' => 1]]);

        $this->assertSame(['users.view' => 1], Group::where('name', 'Sneaky')->first()->permissionMap());
    }

    public function test_a_manager_cannot_edit_a_group_beyond_their_reach(): void
    {
        $this->manager();
        $admins = Group::factory()->withPermissions(['superuser' => 1])->create();

        $this->get(route('groups.edit', $admins))->assertRedirect(route('groups.show', $admins));
        $this->put(route('groups.update', $admins), ['name' => 'X', 'permissions' => []])->assertForbidden();
    }

    public function test_another_super_user_can_be_demoted(): void
    {
        $this->signInAsSuperUser();
        $other = User::factory()->superUser()->create();

        $this->put(route('users.access.update', $other), ['permissions' => ['superuser' => 0]])->assertSessionHas('success');
        $this->assertFalse($other->fresh()->isSuperUser());
    }

    public function test_the_only_super_user_group_cannot_be_deleted_or_emptied_of_super_user(): void
    {
        $admins = Group::factory()->withPermissions(['superuser' => 1])->create();
        $admin = User::factory()->create();
        $admin->groups()->attach($admins);
        $this->actingAs($admin->fresh());

        $this->delete(route('groups.destroy', $admins))->assertSessionHas('error');
        $this->put(route('groups.update', $admins), ['name' => $admins->name, 'permissions' => ['superuser' => 0]])->assertSessionHas('error');

        $this->assertDatabaseHas('groups', ['id' => $admins->id]);
        $this->assertTrue($admin->fresh()->isSuperUser());
    }
}
