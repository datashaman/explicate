<?php

namespace Database\Factories;

use App\Enums\Provider;
use App\Models\Agent;
use App\Models\AgentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentVersion>
 */
class AgentVersionFactory extends Factory
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
            'provider' => Provider::Anthropic,
            'model' => 'claude-sonnet-4-6',
            'reasoning_effort' => null,
            'prompt' => fake()->paragraph(),
            'allowed_tools' => null,
        ];
    }
}
