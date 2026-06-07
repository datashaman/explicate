<?php

namespace App\Actions\Agents;

use App\Models\Agent;
use App\Models\Workspace;

class CreateAgent
{
    public function handle(
        Workspace $workspace,
        string $name,
        string $provider,
        string $model,
        ?string $reasoningEffort,
        ?string $prompt,
    ): Agent {
        $agent = $workspace->agents()->create(['name' => $name]);

        $agent->versions()->create([
            'provider' => $provider,
            'model' => $model,
            'reasoning_effort' => $reasoningEffort ?: null,
            'prompt' => $prompt ?: null,
        ]);

        return $agent->fresh(['latestVersion']);
    }
}
