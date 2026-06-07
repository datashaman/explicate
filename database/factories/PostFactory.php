<?php

namespace Database\Factories;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'topic_id' => Topic::factory(),
            'thread_id' => null,
            'sender_principal_id' => null,
            'title' => $name,
            'slug' => fn (array $attributes): string => Str::slug($attributes['title']),
            'ulid' => fn (): string => (string) Str::ulid(),
            'body' => fake()->optional()->paragraphs(3, true),
            'status' => PostStatus::Draft,
        ];
    }

    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
