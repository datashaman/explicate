<?php

namespace App\Actions\Agents;

use App\Models\Agent;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

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
        ?array $allowedTools = null,
    ): Agent {
        return DB::transaction(function () use ($workspace, $name, $provider, $model, $reasoningEffort, $prompt, $allowedTools): Agent {
            $agent = $workspace->agents()->create(['name' => $name]);

            $this->createAgentVersion->handle($agent, $provider, $model, $reasoningEffort, $prompt, $allowedTools);

            return $agent->fresh(['latestVersion']);
        });
    }
}
