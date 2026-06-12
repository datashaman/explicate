<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
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
            'expected_artifact' => null,
            'status' => TaskStatus::Pending,
            'position' => 1,
        ];
    }

    /**
     * Indicate that the task is complete.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::Done,
        ]);
    }
}
