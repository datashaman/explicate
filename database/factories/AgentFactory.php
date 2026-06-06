<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'workspace_id' => Workspace::factory(),
            'name' => $name,
            'slug' => fn (array $attributes): string => Str::slug($attributes['name']),
        ];
    }

    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
