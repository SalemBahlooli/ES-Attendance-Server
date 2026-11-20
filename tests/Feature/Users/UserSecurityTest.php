<?php

namespace Tests\Feature\Users;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The "Sign-in & security" tab: needs users.sign_in. */
class UserSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return $this->signInWith(['users.view', 'users.edit', 'users.sign_in']);
    }

    private function addSession(User $user, string $id): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(), 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/140.0']);
    }

    public function test_it_needs_the_sign_in_permission(): void
    {
        $this->signInWith(['users.view', 'users.edit']);
        $user = User::factory()->create();

        $this->post(route('users.security.active', $user))->assertForbidden();
    }

    public function test_turning_an_account_off_signs_it_out_everywhere(): void
    {
        $this->admin();
        $user = User::factory()->create();
        $this->addSession($user, 's1');
        $user->createToken('script');

        $this->post(route('users.security.active', $user))->assertRedirect();

        $this->assertFalse($user->fresh()->is_active);
        $this->assertDatabaseMissing('sessions', ['id' => 's1']);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'deactivated', 'subject_id' => $user->id]);
    }

    public function test_nobody_turns_off_their_own_account(): void
    {
        $me = $this->admin();

        $this->post(route('users.security.active', $me))->assertSessionHas('error');
        $this->assertTrue($me->fresh()->is_active);
    }

    public function test_set_a_password(): void
    {
        $this->admin();
        $user = User::factory()->create();

        $this->put(route('users.security.password', $user), ['password' => 'Brand-new-1', 'password_confirmation' => 'Brand-new-1'])
            ->assertSessionHas('success');

        $this->assertTrue(Hash::check('Brand-new-1', $user->fresh()->password));
    }

    public function test_unlink_the_authenticator(): void
    {
        $this->admin();
        $user = User::factory()->withTwoFactor()->create();

        $this->delete(route('users.security.two-factor', $user))->assertSessionHas('success');

        $this->assertFalse($user->fresh()->hasTwoFactor());
    }

    public function test_sign_out_one_device_or_all(): void
    {
        $this->admin();
        $user = User::factory()->create();
        $this->addSession($user, 'a');
        $this->addSession($user, 'b');
        $this->addSession($user, 'c');

        $this->delete(route('users.security.revoke', [$user, 'a']))->assertSessionHas('success');
        $this->assertDatabaseMissing('sessions', ['id' => 'a']);
        $this->assertDatabaseHas('sessions', ['id' => 'b']);

        $this->delete(route('users.security.revoke-all', $user))->assertSessionHas('success');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_a_manager_cannot_touch_a_super_users_sign_in(): void
    {
        $this->admin();
        $admin = User::factory()->superUser()->create();

        $this->post(route('users.security.active', $admin))->assertForbidden();
        $this->assertTrue($admin->fresh()->is_active);
    }
}
