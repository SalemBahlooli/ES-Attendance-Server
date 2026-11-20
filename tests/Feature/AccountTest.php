<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_my_profile_and_photo(): void
    {
        Storage::fake('public');
        $user = $this->signInWith();

        $this->put(route('account.profile.update'), [
            'name' => 'New Name', 'email' => $user->email, 'phone' => '0500000000', 'locale' => 'ar',
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ])->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('ar', $user->locale);
        Storage::disk('public')->assertExists($user->avatar_path);
    }

    public function test_name_and_email_can_be_locked(): void
    {
        $this->setSettings(['profile_edit' => false]);
        $user = $this->signInWith();

        $this->put(route('account.profile.update'), ['name' => 'Changed', 'email' => 'other@example.com', 'phone' => '1']);

        $this->assertNotSame('Changed', $user->fresh()->name);
        $this->assertSame('1', $user->fresh()->phone);
    }

    public function test_change_my_password_signs_out_other_devices(): void
    {
        $user = $this->signInWith();
        DB::table('sessions')->insert(['id' => 'other', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->put(route('account.password.update'), [
            'current_password' => 'wrong', 'password' => 'Another-pass-1', 'password_confirmation' => 'Another-pass-1',
        ])->assertSessionHasErrors('current_password');

        $this->put(route('account.password.update'), [
            'current_password' => 'password', 'password' => 'Another-pass-1', 'password_confirmation' => 'Another-pass-1',
        ])->assertSessionHas('success');

        $this->assertTrue(Hash::check('Another-pass-1', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'other']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'password_changed', 'subject_id' => $user->id]);
    }

    public function test_sign_out_my_other_devices(): void
    {
        $user = $this->signInWith();
        DB::table('sessions')->insert(['id' => 'x', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->delete(route('account.security.revoke', 'x'))->assertSessionHas('success');
        $this->assertDatabaseMissing('sessions', ['id' => 'x']);
    }

    public function test_api_tokens_need_their_permission(): void
    {
        $this->signInWith();
        $this->get(route('account.tokens'))->assertForbidden();

        $user = $this->signInWith(['self.api']);
        $this->post(route('account.tokens.store'), ['name' => 'Script', 'expires_in' => 30])->assertSessionHas('new_token');
        $this->assertSame(1, $user->tokens()->count());
    }
}
