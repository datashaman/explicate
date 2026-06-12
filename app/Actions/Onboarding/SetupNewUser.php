<?php

namespace App\Actions\Onboarding;

use App\Actions\Agents\CreateAgent;
use App\Actions\Agents\DefaultAgentDefinitions;
use App\Actions\Workspaces\CreateWorkspace;
use App\Models\User;

class SetupNewUser
{
    public function __construct(
        private CreateWorkspace $createWorkspace,
        private CreateAgent $createAgent,
        private DefaultAgentDefinitions $defaultAgentDefinitions,
    ) {}

    public function handle(User $user): void
    {
        $team = $user->currentTeam;

        $workspace = $this->createWorkspace->handle($team, 'My Workspace');

        $user->switchWorkspace($workspace);

        foreach ($this->defaultAgentDefinitions->all() as $agent) {
            $this->createAgent->handle(
                workspace: $workspace,
                name: $agent['name'],
                provider: $agent['provider']->value,
                model: $agent['model'],
                reasoningEffort: $agent['reasoning_effort']?->value,
                prompt: $agent['prompt'],
            );
        }
    }
}
