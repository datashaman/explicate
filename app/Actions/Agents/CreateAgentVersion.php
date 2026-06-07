<?php

namespace App\Actions\Agents;

use App\Models\Agent;
use App\Models\AgentVersion;

class CreateAgentVersion
{
    public function handle(
        Agent $agent,
        string $provider,
        string $model,
        ?string $reasoningEffort,
        ?string $prompt,
    ): AgentVersion {
        return $agent->versions()->create([
            'provider' => $provider,
            'model' => $model,
            'reasoning_effort' => $reasoningEffort ?: null,
            'prompt' => $prompt ?: null,
        ]);
    }
}
