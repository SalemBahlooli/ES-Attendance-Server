<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\ChangelogEntry;
use App\Models\Group;
use App\Models\User;
use App\Support\Activity;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Every page opens for a super user, in English and Arabic. */
class PagesTest extends TestCase
{
    use RefreshDatabase;

    public static function locales(): array
    {
        return [['en'], ['ar']];
    }

    #[DataProvider('locales')]
    public function test_every_page_opens(string $locale): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $admin->forceFill(['locale' => $locale])->save();
        $this->actingAs($admin);

        $user = User::factory()->withTwoFactor()->create();
        $user->groups()->attach(Group::first());
        Activity::log('exported', $user, 'Test');
        Announcement::create(['title' => 'Hello', 'type' => 'info', 'is_active' => true]);

        $pages = [
            route('dashboard'), route('users.index'), route('users.create'), route('users.show', $user), route('users.edit', $user),
            route('groups.index'), route('groups.create'), route('groups.show', Group::first()), route('groups.edit', Group::first()), route('groups.report'),
            route('activity.index'), route('activity.show', ActivityLog::first()), route('sign-ins.index'), route('presence.index'),
            route('announcements.index'), route('changelog.index'), route('settings.index'), route('settings.system'), route('settings.backups.index'),
            route('account.profile'), route('account.password'), route('account.security'), route('account.tokens'), route('notifications.index'),
            route('two-factor.setup'),
        ];

        foreach ($pages as $url) {
            $this->get($url)->assertOk();
        }

        $this->assertSame(1, ChangelogEntry::count());
    }

    public function test_seeding_twice_creates_nothing_new(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertSame(5, Group::count()); // Administrators, Managers, Staff + Supervisors, Viewers
        $this->assertTrue(User::first()->isSuperUser());
    }

    public function test_error_pages_render(): void
    {
        $this->get('/does-not-exist')->assertNotFound()->assertSee('404');
        $this->signInWith();
        $this->get(route('users.index'))->assertForbidden()->assertSee('403');
    }

    public function test_create_admin_command(): void
    {
        $this->artisan('app:create-admin', ['--email' => 'boss@example.com', '--name' => 'Boss', '--password' => 'Strong-pass-1'])
            ->assertSuccessful();

        $this->assertTrue(User::where('email', 'boss@example.com')->first()->isSuperUser());
    }
}
