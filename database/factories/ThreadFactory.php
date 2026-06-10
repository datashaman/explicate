<?php

namespace Database\Factories;

use App\Models\Thread;
use App\Models\Topic;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Thread>
 */
class ThreadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->words(3, true);

        return [
            'workspace_id' => Workspace::factory(),
            'topic_id' => null,
            'title' => $title,
            'slug' => fn (array $attributes): string => Str::slug($attributes['title']),
            'summary' => fake()->optional()->paragraph(),
        ];
    }

    public function forTopic(Topic $topic): static
    {
        return $this->state(fn (array $attributes) => [
            'workspace_id' => $topic->workspace_id,
            'topic_id' => $topic->id,
        ]);
    }

    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
