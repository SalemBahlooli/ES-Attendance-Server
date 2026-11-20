<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional()->phoneNumber(),
            'job_title' => fake()->optional()->jobTitle(),
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /** With direct permissions, e.g. ->withPermissions(['users.view' => 1]) */
    public function withPermissions(array $permissions): static
    {
        return $this->state(fn () => ['permissions' => $permissions]);
    }

    public function superUser(): static
    {
        return $this->withPermissions(['superuser' => 1]);
    }

    /** With a linked authenticator app (the secret is returned by ->two_factor_secret). */
    public function withTwoFactor(): static
    {
        return $this->state(fn () => [
            'two_factor_secret' => (new Google2FA)->generateSecretKey(32),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
