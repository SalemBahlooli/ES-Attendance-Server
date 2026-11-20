<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Notifications\SimpleNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_bell_shows_notifications_and_announcements(): void
    {
        $user = $this->signInWith();
        $user->notify(new SimpleNotification('Report ready', 'Your export finished.', url('/')));
        Announcement::create(['title' => 'Maintenance tonight', 'type' => 'warning', 'is_active' => true]);
        Announcement::create(['title' => 'Old news', 'type' => 'info', 'is_active' => false]);

        $this->postJson(route('heartbeat'))->assertOk()->assertJson(['unread' => 2]);

        $this->getJson(route('notifications.feed'))
            ->assertOk()
            ->assertJsonPath('notifications.0.title', 'Report ready')
            ->assertJsonPath('announcements.0.title', 'Maintenance tonight')
            ->assertJsonCount(1, 'announcements');

        // Opening the bell marks announcements as seen.
        $this->postJson(route('heartbeat'))->assertJson(['unread' => 1]);
    }

    public function test_open_and_read_all(): void
    {
        $user = $this->signInWith();
        $user->notify(new SimpleNotification('One', null, url('/account/profile')));
        $user->notify(new SimpleNotification('Two'));
        $first = $user->notifications()->where('data->title', 'One')->first();

        $this->get(route('notifications.open', $first->id))->assertRedirect(url('/account/profile'));
        $this->assertNotNull($first->fresh()->read_at);

        $this->post(route('notifications.read-all'));
        $this->assertSame(0, $user->unreadNotifications()->count());

        $this->get(route('notifications.index'))->assertOk()->assertSee('Two');
    }

    public function test_announcements_are_managed_with_their_permission(): void
    {
        $this->signInWith();
        $this->get(route('announcements.index'))->assertForbidden();

        $this->signInWith(['announcements.manage']);
        $this->post(route('announcements.store'), ['title' => 'Hello', 'type' => 'info', 'is_active' => '1'])->assertSessionHas('success');
        $this->assertDatabaseHas('announcements', ['title' => 'Hello', 'is_active' => true]);
    }

    public function test_scheduled_announcements_only_show_in_their_window(): void
    {
        Announcement::create(['title' => 'Future', 'type' => 'info', 'is_active' => true, 'starts_at' => now()->addDay()]);
        Announcement::create(['title' => 'Past', 'type' => 'info', 'is_active' => true, 'ends_at' => now()->subDay()]);
        Announcement::create(['title' => 'Now', 'type' => 'info', 'is_active' => true]);

        $this->assertSame(['Now'], Announcement::visible()->pluck('title')->all());
    }

    public function test_presence_is_stamped_by_page_views(): void
    {
        $user = $this->signInWith();

        $this->get(route('account.profile'))->assertOk();

        $user->refresh();
        $this->assertNotNull($user->last_activity_at);
        $this->assertSame('account/profile', $user->last_activity_page);
        $this->assertSame('online', $user->presence());
    }
}
