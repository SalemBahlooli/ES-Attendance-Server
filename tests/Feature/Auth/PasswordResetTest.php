<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_reset_link_is_emailed_and_works(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('success');

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertOk();

        $this->post(route('password.update'), [
            'token' => $token, 'email' => $user->email,
            'password' => 'New-pass-123', 'password_confirmation' => 'New-pass-123',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('New-pass-123', $user->fresh()->password));
    }

    public function test_the_answer_does_not_reveal_whether_an_account_exists(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => 'nobody@example.com'])->assertSessionHas('success');
        Notification::assertNothingSent();
    }

    public function test_reset_with_the_authenticator_code(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('password.code.reset'), [
            'email' => $user->email, 'code' => $this->totp($user->two_factor_secret),
            'password' => 'New-pass-123', 'password_confirmation' => 'New-pass-123',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('New-pass-123', $user->fresh()->password));
    }

    public function test_reset_with_code_is_refused_without_a_linked_app(): void
    {
        $user = User::factory()->create();

        $this->post(route('password.code.reset'), [
            'email' => $user->email, 'code' => '123456',
            'password' => 'New-pass-123', 'password_confirmation' => 'New-pass-123',
        ])->assertSessionHas('error');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_rules_come_from_settings(): void
    {
        $this->setSettings(['password_min_length' => 12, 'password_symbols' => true]);
        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('password.code.reset'), [
            'email' => $user->email, 'code' => $this->totp($user->two_factor_secret),
            'password' => 'Short1', 'password_confirmation' => 'Short1',
        ])->assertSessionHasErrors('password');
    }
}
