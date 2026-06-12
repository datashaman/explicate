<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'text' => fake()->sentence(),
            'done' => false,
            'position' => 1,
        ];
    }

    /**
     * Indicate that the task is complete.
     */
    public function done(): static
    {
        return $this->state(fn (array $attributes) => [
            'done' => true,
        ]);
    }
}
