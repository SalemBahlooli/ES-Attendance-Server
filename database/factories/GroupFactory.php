<?php

namespace Database\Factories;

use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    protected $model = Group::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'permissions' => [],
        ];
    }

    public function withPermissions(array $permissions): static
    {
        return $this->state(fn () => ['permissions' => $permissions]);
    }
}
