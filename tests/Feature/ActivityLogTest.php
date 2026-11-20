<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_changes_are_logged_without_secrets(): void
    {
        $actor = $this->signInAsSuperUser();
        $user = User::factory()->create(['phone' => '111']);

        $user->update(['phone' => '222', 'password' => 'Changed-123']);

        $log = ActivityLog::where('action', 'updated')->where('subject_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame($actor->id, $log->causer_id);
        $this->assertEquals(['old' => '111', 'new' => '222'], $log->changes()['phone']);
        $this->assertArrayNotHasKey('password', $log->changes());
    }

    public function test_presence_stamps_are_not_logged(): void
    {
        $user = User::factory()->create();
        $before = ActivityLog::count();

        $user->update(['last_activity_at' => now()]);

        $this->assertSame($before, ActivityLog::count());
    }

    public function test_requests_that_change_something_are_logged_even_without_code(): void
    {
        Route::middleware(['web', 'auth'])->post('/_test/ping', fn () => back())->name('test.ping');
        $this->signInWith();

        $this->from('/')->post('/_test/ping');

        $this->assertDatabaseHas('activity_logs', ['action' => 'other', 'description' => 'Test › Ping']);
    }

    public function test_failed_requests_are_not_logged(): void
    {
        Route::middleware(['web', 'auth'])->post('/_test/fail', fn () => back()->with('error', 'no'))->name('test.fail');
        $this->signInWith();
        $before = ActivityLog::count();

        $this->from('/')->post('/_test/fail');

        $this->assertSame($before, ActivityLog::count());
    }

    public function test_the_log_pages_and_filters(): void
    {
        $this->signInWith(['activity.view']);
        Activity::log('exported', null, 'Something specific');

        $this->get(route('activity.index'))->assertOk()->assertSee('Something specific');
        $this->get(route('activity.index', ['action' => 'exported', 'q' => 'specific']))->assertOk()->assertSee('Something specific');
        $this->get(route('activity.show', ActivityLog::latest('id')->first()))->assertOk();
    }

    public function test_old_rows_are_pruned_by_the_retention_setting(): void
    {
        $this->setSettings(['activity_retention_days' => 30]);
        Activity::log('exported');
        ActivityLog::query()->update(['created_at' => now()->subDays(40)]);
        Activity::log('exported');

        $this->artisan('model:prune', ['--model' => [ActivityLog::class]])->assertSuccessful();

        $this->assertSame(1, ActivityLog::count());
    }
}
