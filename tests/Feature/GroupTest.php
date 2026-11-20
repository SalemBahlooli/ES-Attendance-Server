<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_a_group_with_allow_and_deny(): void
    {
        $this->signInAsSuperUser();

        $this->post(route('groups.store'), [
            'name' => 'Support', 'description' => 'Help desk',
            'permissions' => ['users.view' => 1, 'users.delete' => -1, 'activity.view' => 0, 'not.a.permission' => 1],
        ])->assertRedirect();

        $group = Group::where('name', 'Support')->firstOrFail();
        $this->assertSame(['users.delete' => -1, 'users.view' => 1], $group->permissionMap());
    }

    public function test_members_get_the_groups_permissions(): void
    {
        $this->signInAsSuperUser();
        $group = Group::factory()->withPermissions(['activity.view' => 1])->create();
        $user = User::factory()->create();
        $user->groups()->attach($group);

        $this->actingAs($user->fresh())->get(route('activity.index'))->assertOk();
    }

    public function test_duplicate_a_group(): void
    {
        $this->signInAsSuperUser();
        $group = Group::factory()->withPermissions(['users.view' => 1])->create(['name' => 'Staff']);

        $this->post(route('groups.clone', $group))->assertRedirect();

        $copy = Group::where('name', 'Staff (copy)')->firstOrFail();
        $this->assertSame($group->permissionMap(), $copy->permissionMap());
        $this->assertSame(0, $copy->users()->count());
    }

    public function test_group_pages_open(): void
    {
        $this->signInAsSuperUser();
        $group = Group::factory()->create();

        $this->get(route('groups.index'))->assertOk();
        $this->get(route('groups.show', $group))->assertOk();
        $this->get(route('groups.edit', $group))->assertOk();
        $this->get(route('groups.report'))->assertOk();
    }

    public function test_viewers_cannot_change_groups(): void
    {
        $this->signInWith(['groups.view']);
        $group = Group::factory()->create();

        $this->get(route('groups.edit', $group))->assertForbidden();
        $this->delete(route('groups.destroy', $group))->assertForbidden();
    }
}
