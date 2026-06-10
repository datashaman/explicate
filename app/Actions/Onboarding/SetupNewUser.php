<?php

namespace App\Actions\Onboarding;

use App\Actions\Agents\CreateAgent;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\Provider;
use App\Models\User;

class SetupNewUser
{
    public function __construct(
        private CreateWorkspace $createWorkspace,
        private CreateAgent $createAgent,
    ) {}

    public function handle(User $user): void
    {
        $team = $user->currentTeam;

        $workspace = $this->createWorkspace->handle($team, 'My Workspace');

        $user->switchWorkspace($workspace);

        $this->createAgent->handle(
            workspace: $workspace,
            name: 'Analyst',
            provider: Provider::Anthropic->value,
            model: 'claude-sonnet-4-6',
            reasoningEffort: null,
            prompt: "You are a spec analyst. Given a user's input or idea, produce a clear, structured specification: define the goal, list constraints and assumptions, break it into requirements, and call out open questions. Be concise and precise.",
        );
    }
}
