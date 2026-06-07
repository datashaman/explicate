<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Models\WorkspaceRepository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceRepository>
 */
class WorkspaceRepositoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => $this->faker->unique()->word(),
            'url' => 'git@github.com:example/'.$this->faker->slug().'.git',
            'branch' => 'main',
            'auth_type' => 'ssh',
            'ssh_private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----fake-----END OPENSSH PRIVATE KEY-----',
            'access_token' => null,
        ];
    }

    public function token(): static
    {
        return $this->state([
            'url' => 'https://github.com/example/'.$this->faker->slug().'.git',
            'auth_type' => 'token',
            'ssh_private_key' => null,
            'access_token' => 'ghp_fake_token',
        ]);
    }
}
