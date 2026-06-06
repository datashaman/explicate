<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'team_id' => Team::factory(),
            'name' => $name,
            'slug' => fn (array $attributes): string => Str::slug($attributes['name']),
        ];
    }

    /**
     * Indicate that the workspace has been deleted.
     */
    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
