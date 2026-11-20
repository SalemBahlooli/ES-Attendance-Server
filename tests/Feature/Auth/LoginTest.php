<?php

namespace Tests\Feature\Auth;

use App\Models\SignIn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_sign_in_page(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertSee(__('Sign in'));
    }

    public function test_a_person_signs_in_with_email_and_password(): void
    {
        $user = User::factory()->create(['email' => 'sara@example.com']);

        $this->post(route('login.attempt'), ['login' => 'sara@example.com', 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('sign_ins', ['user_id' => $user->id, 'successful' => true, 'method' => 'password']);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_the_username_also_works(): void
    {
        $user = User::factory()->create(['username' => 'sara']);

        $this->post(route('login.attempt'), ['login' => 'sara', 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_is_refused_and_logged(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.attempt'), ['login' => $user->email, 'password' => 'wrong'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
        $this->assertDatabaseHas('sign_ins', ['user_id' => $user->id, 'successful' => false, 'reason' => 'bad_password']);
    }

    public function test_an_unknown_email_gets_the_same_answer(): void
    {
        $this->post(route('login.attempt'), ['login' => 'nobody@example.com', 'password' => 'x'])
            ->assertSessionHas('error', __('These credentials do not match our records.'));

        $this->assertDatabaseHas('sign_ins', ['login' => 'nobody@example.com', 'reason' => 'unknown_user']);
    }

    public function test_a_turned_off_account_cannot_sign_in(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post(route('login.attempt'), ['login' => $user->email, 'password' => 'password']);

        $this->assertGuest();
        $this->assertDatabaseHas('sign_ins', ['user_id' => $user->id, 'reason' => 'inactive']);
    }

    public function test_too_many_attempts_lock_the_form(): void
    {
        $this->setSettings(['login_max_attempts' => 3]);
        $user = User::factory()->create();

        foreach (range(1, 3) as $i) {
            $this->post(route('login.attempt'), ['login' => $user->email, 'password' => 'wrong']);
        }

        // Even the right password is refused while locked.
        $this->post(route('login.attempt'), ['login' => $user->email, 'password' => 'password'])
            ->assertSessionHas('error');

        $this->assertGuest();
        $this->assertSame(1, SignIn::where('reason', 'locked_out')->count());
    }

    public function test_an_account_turned_off_while_signed_in_is_signed_out(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get('/')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_sign_out(): void
    {
        $this->actingAs(User::factory()->create())->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_password_sign_in_can_be_turned_off_except_for_super_users(): void
    {
        $this->setSettings(['password_login' => false]);
        $user = User::factory()->create();
        $admin = User::factory()->superUser()->create();

        $this->post(route('login.attempt'), ['login' => $user->email, 'password' => 'password']);
        $this->assertGuest();

        $this->post(route('login.attempt'), ['login' => $admin->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($admin);
    }

    public function test_the_language_can_be_switched_before_signing_in(): void
    {
        $this->post(route('locale.switch'), ['locale' => 'ar'])->assertRedirect();
        $this->get(route('login'))->assertSee('dir="rtl"', false)->assertSee('تسجيل الدخول');
    }
}
