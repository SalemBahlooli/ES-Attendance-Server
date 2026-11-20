<?php

namespace Tests\Feature;

use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_come_from_the_config_file(): void
    {
        $this->assertSame('optional', setting('two_factor_mode'));
        $this->assertSame(8, setting('password_min_length'));
        $this->assertFalse(setting('allow_registration'));
        $this->assertSame('fallback', setting('no_such_key', 'fallback'));
    }

    public function test_saving_from_the_page(): void
    {
        $this->signInWith(['settings.manage']);

        $this->put(route('settings.update', 'general'), [
            'app_name' => 'Acme Portal', 'company_name' => 'Acme', 'support_email' => '',
            'default_locale' => 'ar', 'timezone' => 'Asia/Riyadh', 'date_format' => 'd/m/Y',
            'time_format' => 'H:i', 'per_page' => 50,
        ])->assertRedirect(route('settings.edit', 'general'));

        $this->assertSame('Acme Portal', setting('app_name'));
        $this->assertSame(50, setting('per_page'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'settings_changed']);
        $this->get(route('login'))->assertDontSee('Acme Portal'); // signed in → redirected, page not shown
    }

    public function test_toggles_save_as_booleans(): void
    {
        $this->signInWith(['settings.manage']);

        $this->put(route('settings.update', 'registration'), ['allow_registration' => '1', 'registration_domains' => '', 'default_group' => '', 'profile_edit' => '0']);

        $this->assertTrue(setting('allow_registration'));
        $this->assertFalse(setting('profile_edit'));
    }

    public function test_validation_uses_the_declared_rules(): void
    {
        $this->signInWith(['settings.manage']);

        $this->put(route('settings.update', 'general'), ['app_name' => '', 'per_page' => 9999])
            ->assertSessionHasErrors(['app_name', 'per_page']);
    }

    public function test_secrets_are_encrypted_and_kept_when_left_empty(): void
    {
        $this->signInWith(['settings.manage']);
        $base = ['microsoft_enabled' => '1', 'microsoft_client_id' => 'abc', 'microsoft_tenant' => 'common', 'microsoft_auto_create' => '0'];

        $this->put(route('settings.update', 'sso'), $base + ['microsoft_client_secret' => 's3cret']);
        $this->assertSame('s3cret', setting('microsoft_client_secret'));
        $this->assertNotSame('s3cret', DB::table('settings')->where('key', 'microsoft_client_secret')->value('value'));

        $this->put(route('settings.update', 'sso'), $base + ['microsoft_client_secret' => '']);
        $this->assertSame('s3cret', setting('microsoft_client_secret'));
    }

    public function test_logo_upload(): void
    {
        Storage::fake('public');
        $this->signInWith(['settings.manage']);

        $this->put(route('settings.update', 'branding'), [
            'logo' => UploadedFile::fake()->image('logo.png'), 'primary_color' => '#0f766e', 'header_brand' => 'logo',
            'login_title' => 'Hi', 'login_subtitle' => '', 'footer_text' => '',
        ])->assertSessionHasNoErrors();

        Storage::disk('public')->assertExists(setting('logo'));
        $this->get('/')->assertSee('--brand: #0f766e', false);
    }

    public function test_unknown_keys_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(Settings::class)->set(['typo_setting' => 1]);
    }

    public function test_settings_need_the_permission(): void
    {
        $this->signInWith(['users.view']);

        $this->get(route('settings.index'))->assertForbidden();
        $this->get(route('settings.edit', 'security'))->assertForbidden();
        $this->get(route('settings.backups.index'))->assertForbidden();
    }

    public function test_every_settings_page_opens(): void
    {
        $this->signInAsSuperUser();

        foreach (array_keys(app(Settings::class)->groups()) as $group) {
            $this->get(route('settings.edit', $group))->assertOk();
        }
        $this->get(route('settings.system'))->assertOk();
        $this->get(route('settings.backups.index'))->assertOk();
    }
}
