<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_api_needs_a_token(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_me_with_a_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonMissingPath('data.password');
    }

    public function test_a_token_can_only_do_what_its_owner_can(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/users')->assertForbidden();

    }

    public function test_a_token_with_the_permission_lists_users(): void
    {
        $boss = User::factory()->withPermissions(['users.view' => 1])->create();
        $this->withToken($boss->createToken('test')->plainTextToken)->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'email']], 'meta']);
    }

    public function test_a_turned_off_account_loses_api_access(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $user->forceFill(['is_active' => false])->save();

        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden();
    }
}
