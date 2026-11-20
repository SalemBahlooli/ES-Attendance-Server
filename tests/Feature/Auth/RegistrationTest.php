<?php

namespace Tests\Feature\Auth;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_up_is_off_by_default(): void
    {
        $this->get(route('register'))->assertNotFound();
    }

    public function test_sign_up_creates_an_account_in_the_default_group(): void
    {
        $group = Group::factory()->create();
        $this->setSettings(['allow_registration' => true, 'default_group' => (string) $group->id]);

        $this->post(route('register.store'), [
            'name' => 'New Person', 'email' => 'new@example.com',
            'password' => 'Secret-123', 'password_confirmation' => 'Secret-123',
        ])->assertRedirect(route('dashboard'));

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->groups->contains($group));
        $this->assertNull($user->permissions);
    }

    public function test_sign_up_can_be_limited_to_email_domains(): void
    {
        $this->setSettings(['allow_registration' => true, 'registration_domains' => 'company.com']);

        $this->post(route('register.store'), [
            'name' => 'Outsider', 'email' => 'x@gmail.com',
            'password' => 'Secret-123', 'password_confirmation' => 'Secret-123',
        ])->assertSessionHasErrors('email');
    }
}
