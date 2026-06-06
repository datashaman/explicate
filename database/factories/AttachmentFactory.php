<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = fake()->word().'.txt';

        return [
            'post_id' => Post::factory(),
            'filename' => $filename,
            'path' => 'attachments/'.Str::uuid().'/'.$filename,
            'mime_type' => 'text/plain',
            'size' => fake()->numberBetween(1024, 1048576),
        ];
    }

    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
