<?php

namespace Database\Factories;

use App\Enums\BriefCategory;
use App\Models\Brief;
use App\Models\Thread;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Brief>
 */
class BriefFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'source_thread_id' => null,
            'category' => fake()->randomElement(BriefCategory::cases()),
            'summary' => fake()->sentence(6),
            'current_behaviour' => fake()->paragraph(),
            'expected_behaviour' => fake()->paragraph(),
            'acceptance_criteria' => [
                ['text' => fake()->sentence(), 'done' => false],
                ['text' => fake()->sentence(), 'done' => false],
            ],
            'out_of_scope' => fake()->paragraph(),
        ];
    }

    /**
     * Indicate that the brief describes a bug.
     */
    public function bug(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => BriefCategory::Bug,
        ]);
    }

    /**
     * Indicate that the brief describes a feature.
     */
    public function feature(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => BriefCategory::Feature,
        ]);
    }

    /**
     * Associate the brief with a thread.
     */
    public function forThread(Thread $thread): static
    {
        return $this->state(fn (array $attributes) => [
            'workspace_id' => $thread->workspace_id,
            'source_thread_id' => $thread->id,
        ]);
    }

    /**
     * Indicate that the brief has been deleted.
     */
    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
