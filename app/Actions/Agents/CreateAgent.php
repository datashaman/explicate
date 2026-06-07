<?php

namespace App\Actions\Agents;

use App\Models\Agent;
use App\Models\Workspace;

class CreateAgent
{
    public function __construct(private CreateAgentVersion $createAgentVersion) {}

    public function handle(
        Workspace $workspace,
        string $name,
        string $provider,
        string $model,
        ?string $reasoningEffort,
        ?string $prompt,
    ): Agent {
        $agent = $workspace->agents()->create(['name' => $name]);

        $this->createAgentVersion->handle($agent, $provider, $model, $reasoningEffort, $prompt);

        return $agent->fresh(['latestVersion']);
    }
}
