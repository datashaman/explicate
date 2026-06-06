<?php

namespace Database\Factories;

use App\Enums\AgentTaskStatus;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\Message;
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
            'message_id' => Message::factory(),
            'event_type' => 'message_received',
            'status' => AgentTaskStatus::Pending,
            'priority' => 0,
            'available_at' => now(),
            'locked_at' => null,
            'attempts' => 0,
            'last_error' => null,
        ];
    }
}
