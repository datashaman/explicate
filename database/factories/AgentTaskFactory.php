<?php

namespace Database\Factories;

use App\Enums\AgentTaskStatus;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentTask>
 */
class AgentTaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'post_id' => Post::factory(),
            'event_type' => AgentTask::EventPostMentioned,
            'status' => AgentTaskStatus::Pending,
            'priority' => 0,
            'available_at' => now(),
            'locked_at' => null,
            'attempts' => 0,
            'last_error' => null,
        ];
    }
}
