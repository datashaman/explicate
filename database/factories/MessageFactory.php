<?php

namespace Database\Factories;

use App\Enums\MessageStatus;
use App\Models\Message;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'topic_id' => Topic::factory(),
            'sender_principal_id' => null,
            'recipient_principal_id' => null,
            'title' => $name,
            'slug' => Str::slug($name),
            'body' => fake()->optional()->paragraphs(3, true),
            'status' => MessageStatus::Draft,
        ];
    }

    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
