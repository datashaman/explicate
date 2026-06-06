<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Principal;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Principal>
 */
class PrincipalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $workspace = Workspace::factory();
        $user = User::factory();

        return [
            'workspace_id' => $workspace,
            'type' => Principal::TypeUser,
            'user_id' => $user,
            'agent_id' => null,
        ];
    }

    public function agent(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Principal::TypeAgent,
            'user_id' => null,
            'agent_id' => Agent::factory(),
        ]);
    }
}
