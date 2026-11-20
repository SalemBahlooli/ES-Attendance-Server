<?php

namespace Tests\Feature\Users;

use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\User;
use App\Notifications\WelcomeUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_searches_and_filters(): void
    {
        $this->signInWith(['users.view']);
        User::factory()->create(['name' => 'Huda Al-Harbi']);
        User::factory()->create(['name' => 'Omar Saleh']);
        User::factory()->inactive()->create(['name' => 'Retired Person']);

        $this->get(route('users.index', ['q' => 'huda']))->assertSee('Huda Al-Harbi')->assertDontSee('Omar Saleh');
        $this->get(route('users.index', ['status' => 'inactive']))->assertSee('Retired Person')->assertDontSee('Omar Saleh');
    }

    public function test_creating_a_user_sends_the_welcome_email(): void
    {
        Notification::fake();
        $creator = $this->signInWith(['users.view', 'users.create', 'users.permissions']);
        $creator->forceFill(['permissions' => array_merge($creator->permissions, ['dashboard.view' => 1])])->save();
        $group = Group::factory()->withPermissions(['dashboard.view' => 1])->create();

        $this->post(route('users.store'), [
            'name' => 'Lina Fahad', 'email' => 'lina@example.com', 'send_welcome' => 1, 'groups' => [$group->id],
        ])->assertRedirect();

        $user = User::where('email', 'lina@example.com')->firstOrFail();
        $this->assertSame($creator->id, $user->created_by);
        $this->assertTrue($user->groups->contains($group));
        $this->assertNull($user->password);
        Notification::assertSentTo($user, WelcomeUser::class);
        $this->assertDatabaseHas('activity_logs', ['action' => 'created', 'subject_id' => $user->id, 'causer_id' => $creator->id]);
    }

    public function test_emails_are_unique(): void
    {
        $this->signInWith(['users.view', 'users.create']);
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post(route('users.store'), ['name' => 'X', 'email' => 'taken@example.com'])->assertSessionHasErrors('email');
    }

    public function test_editing_logs_what_changed(): void
    {
        $this->signInWith(['users.view', 'users.edit']);
        $user = User::factory()->create(['job_title' => 'Clerk']);

        $this->put(route('users.update', $user), ['name' => $user->name, 'email' => $user->email, 'job_title' => 'Manager'])->assertRedirect();

        $log = ActivityLog::where('action', 'updated')->where('subject_id', $user->id)->firstOrFail();
        $this->assertEquals(['old' => 'Clerk', 'new' => 'Manager'], $log->changes()['job_title']);
    }

    public function test_only_a_super_user_edits_a_super_user(): void
    {
        $this->signInWith(['users.view', 'users.edit']);
        $admin = User::factory()->superUser()->create();

        $this->get(route('users.edit', $admin))->assertForbidden();
    }

    public function test_delete_restore_and_delete_permanently(): void
    {
        $actor = $this->signInWith(['users.view', 'users.delete']);
        $user = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->delete(route('users.destroy', $user))->assertRedirect(route('users.index'));
        $this->assertSoftDeleted($user);
        $this->assertDatabaseMissing('sessions', ['id' => 'abc']);

        $this->get(route('users.show', $user))->assertOk();
        $this->post(route('users.restore', $user))->assertRedirect();
        $this->assertNotSoftDeleted($user);

        // Permanent delete is for super users only.
        $user->delete();
        $this->delete(route('users.force-delete', $user))->assertForbidden();
        $this->signInAsSuperUser();
        $this->delete(route('users.force-delete', $user))->assertRedirect();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_nobody_deletes_their_own_account(): void
    {
        $me = $this->signInWith(['users.view', 'users.delete']);

        $this->delete(route('users.destroy', $me))->assertSessionHas('error');
        $this->assertNotSoftDeleted($me);
    }

    public function test_export_needs_its_permission_and_is_logged(): void
    {
        $this->signInWith(['users.view']);
        $this->get(route('users.export'))->assertForbidden();

        $this->signInWith(['users.view', 'users.export']);
        $response = $this->get(route('users.export'));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'exported']);
    }

    public function test_user_page_tabs_open(): void
    {
        $this->signInAsSuperUser();
        $user = User::factory()->withTwoFactor()->create();

        foreach (['overview', 'access', 'security', 'activity'] as $tab) {
            $this->get(route('users.show', ['user' => $user, 'tab' => $tab]))->assertOk();
        }
    }
}
