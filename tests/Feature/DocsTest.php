<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Documentation page: open to every signed-in account, closed to guests. */
class DocsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(route('docs.index'))->assertRedirect(route('login'));
    }

    public function test_any_signed_in_account_can_read_it(): void
    {
        $this->signInWith();

        $this->get(route('docs.index'))
            ->assertOk()
            ->assertSee('How a day is counted')
            ->assertSee('Try it yourself')
            ->assertDontSee(route('shifts.index')); // no link to a page the account cannot open
    }

    public function test_it_uses_the_live_settings_and_the_default_shift(): void
    {
        Shift::create(['name' => 'Morning', 'start_time' => '07:30', 'end_time' => '15:30', 'grace_in' => 10, 'grace_out' => 5, 'weekend' => [5, 6], 'is_default' => true]);
        app(Settings::class)->set(['timesheet_start_day' => 1, 'feature_overtime' => true]);
        $this->signInWith(['shifts.manage']);

        $this->get(route('docs.index'))
            ->assertOk()
            ->assertSee('07:30')
            ->assertSee(route('shifts.index'))
            ->assertSee('starts on day 1', false);
    }

    public function test_it_opens_in_arabic(): void
    {
        $user = $this->signInWith();
        $user->forceFill(['locale' => 'ar'])->save();

        $this->get(route('docs.index'))->assertOk()->assertSee('كيف يُحسب اليوم');
    }
}
