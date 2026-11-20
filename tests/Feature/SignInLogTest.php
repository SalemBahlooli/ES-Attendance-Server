<?php

namespace Tests\Feature;

use App\Models\SignIn;
use App\Models\User;
use App\Notifications\AccountLockedOut;
use App\Notifications\NewDeviceSignIn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SignInLogTest extends TestCase
{
    use RefreshDatabase;

    private const WINDOWS_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';

    private const IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private function signIn(User $user, string $agent): void
    {
        $this->withHeader('User-Agent', $agent)
            ->post(route('login.attempt'), ['login' => $user->email, 'password' => 'password']);
        $this->post(route('logout'));
    }

    public function test_a_new_device_is_flagged_and_the_person_is_told(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->signIn($user, self::WINDOWS_CHROME); // first ever: nothing to compare
        $this->signIn($user, self::WINDOWS_CHROME); // known device
        $this->signIn($user, self::IPHONE_SAFARI);  // new

        $this->assertSame([false, false, true], SignIn::where('user_id', $user->id)->orderBy('id')->pluck('new_device')->all());
        Notification::assertSentToTimes($user, NewDeviceSignIn::class, 1);
    }

    public function test_new_device_emails_can_be_turned_off(): void
    {
        Notification::fake();
        $this->setSettings(['new_device_alerts' => false]);
        $user = User::factory()->create();

        $this->signIn($user, self::WINDOWS_CHROME);
        $this->signIn($user, self::IPHONE_SAFARI);

        Notification::assertNothingSent();
    }

    public function test_admins_are_alerted_on_repeated_lockouts(): void
    {
        Notification::fake();
        $this->setSettings(['login_max_attempts' => 3, 'lockout_alert_threshold' => 2]);
        $admin = User::factory()->superUser()->create();
        $user = User::factory()->create();

        foreach (range(1, 5) as $i) {
            $this->post(route('login.attempt'), ['login' => $user->email, 'password' => 'wrong']);
        }

        Notification::assertSentTo($admin, AccountLockedOut::class);
    }

    public function test_the_sign_in_log_page(): void
    {
        $this->signInWith(['signins.view']);
        SignIn::create(['login' => 'ghost@example.com', 'successful' => false, 'reason' => 'unknown_user', 'created_at' => now()]);

        $this->get(route('sign-ins.index'))->assertOk()->assertSee(__('Unknown account'));
        $this->get(route('sign-ins.index', ['result' => 'failed', 'login' => 'ghost']))->assertOk();
    }

    public function test_my_security_page_lists_my_sign_ins(): void
    {
        $user = $this->signInWith();
        SignIn::create(['user_id' => $user->id, 'login' => $user->email, 'successful' => true, 'method' => 'password', 'user_agent' => self::IPHONE_SAFARI, 'created_at' => now()]);

        $this->get(route('account.security'))->assertOk()->assertSee('Safari on iOS');
    }
}
