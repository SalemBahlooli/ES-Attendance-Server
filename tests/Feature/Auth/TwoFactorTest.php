<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_linked_account_must_enter_the_code_after_the_password(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('login.attempt'), ['login' => $user->email, 'password' => 'password']);
        $this->get('/')->assertRedirect(route('two-factor.challenge'));
        $this->get(route('users.index'))->assertRedirect(route('two-factor.challenge'));

        $this->post(route('two-factor.verify'), ['code' => $this->totp($user->two_factor_secret)])
            ->assertRedirect();

        $this->get('/')->assertOk();
    }

    public function test_a_wrong_code_is_refused(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $this->actingAs($user);

        $this->post(route('two-factor.verify'), ['code' => '000000'])->assertSessionHas('error');
        $this->get('/')->assertRedirect(route('two-factor.challenge'));
        $this->assertDatabaseHas('sign_ins', ['user_id' => $user->id, 'reason' => 'two_factor_invalid']);
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $code = $this->totp($user->two_factor_secret);

        $this->assertSame(TwoFactor::OK, TwoFactor::check($user, $code));
        $this->assertSame(TwoFactor::USED, TwoFactor::check($user, $code));
    }

    public function test_sign_in_with_the_authenticator_code_only(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('login.attempt'), ['method' => 'code', 'login' => $user->email, 'code' => $this->totp($user->two_factor_secret)])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertOk(); // already passed two-factor
        $this->assertDatabaseHas('sign_ins', ['user_id' => $user->id, 'method' => 'authenticator', 'successful' => true]);
    }

    public function test_code_sign_in_is_refused_for_accounts_without_an_app(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.attempt'), ['method' => 'code', 'login' => $user->email, 'code' => '123456'])
            ->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_code_sign_in_pauses_after_five_wrong_codes(): void
    {
        $this->setSettings(['login_max_attempts' => 50]);
        $user = User::factory()->withTwoFactor()->create();

        foreach (range(1, 5) as $i) {
            $this->post(route('login.attempt'), ['method' => 'code', 'login' => $user->email, 'code' => '000000']);
        }

        $this->post(route('login.attempt'), ['method' => 'code', 'login' => $user->email, 'code' => $this->totp($user->two_factor_secret)]);

        $this->assertGuest();
        $this->assertDatabaseHas('sign_ins', ['user_id' => $user->id, 'reason' => 'code_paused']);
    }

    public function test_required_mode_sends_people_without_an_app_to_link_one(): void
    {
        $this->setSettings(['two_factor_mode' => 'required']);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertRedirect(route('two-factor.setup'));
        $this->get(route('two-factor.setup'))->assertOk()->assertSee('<svg', false);
    }

    public function test_linking_an_app(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('two-factor.setup'))->assertOk();

        $secret = session('two_factor.pending_secret');
        $this->post(route('two-factor.confirm'), ['code' => $this->totp($secret)])->assertRedirect();

        $user->refresh();
        $this->assertTrue($user->hasTwoFactor());
        $this->assertSame($secret, $user->two_factor_secret);
        $this->assertDatabaseHas('activity_logs', ['action' => 'two_factor_enabled', 'subject_id' => $user->id]);
    }

    public function test_the_secret_is_stored_encrypted(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $raw = \DB::table('users')->where('id', $user->id)->value('two_factor_secret');

        $this->assertNotSame($user->two_factor_secret, $raw);
    }

    public function test_turning_it_off_needs_the_permission_and_the_password(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        TwoFactor::markPassed($user);
        $this->actingAs($user)->withSession([TwoFactor::SESSION_KEY => $user->id]);

        $this->delete(route('two-factor.disable'), ['password' => 'password'])->assertSessionHas('error');
        $this->assertTrue($user->fresh()->hasTwoFactor());

        $user->forceFill(['permissions' => ['self.two_factor_disable' => 1]])->save();
        $this->actingAs($user->fresh());
        $this->delete(route('two-factor.disable'), ['password' => 'wrong'])->assertSessionHas('error');
        $this->delete(route('two-factor.disable'), ['password' => 'password'])->assertSessionHas('success');
        $this->assertFalse($user->fresh()->hasTwoFactor());
    }

    public function test_a_trusted_browser_skips_the_code(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $this->actingAs($user);

        $response = $this->post(route('two-factor.verify'), ['code' => $this->totp($user->two_factor_secret), 'remember_device' => 1]);
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === TwoFactor::REMEMBER_COOKIE);
        $this->assertNotNull($cookie);

        // New session, same browser cookie → no code needed.
        $plain = CookieValuePrefix::remove(decrypt($cookie->getValue(), false));
        $this->flushSession();
        $this->actingAs($user)->withCookie(TwoFactor::REMEMBER_COOKIE, $plain)->get('/')->assertOk();

        // A forged cookie does not work.
        $this->flushSession();
        $this->actingAs($user)->withCookie(TwoFactor::REMEMBER_COOKIE, 'forged')->get('/')->assertRedirect(route('two-factor.challenge'));
    }
}
