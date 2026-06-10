<?php

namespace App\Services;

use App\Models\ProviderKey;
use App\Models\Workspace;
use Closure;
use Laravel\Ai\Ai;

class AiProviderKeyService
{
    public function forWorkspace(Workspace $workspace, string $providerName): ?string
    {
        $providerKey = ProviderKey::query()
            ->where('provider', $providerName)
            ->where(fn ($query) => $query
                ->where('workspace_id', $workspace->id)
                ->orWhere(fn ($query) => $query
                    ->where('team_id', $workspace->team_id)
                    ->whereNull('workspace_id')
                )
            )
            ->orderByRaw('workspace_id IS NULL')
            ->first();

        return filled($providerKey?->api_key) ? $providerKey->api_key : null;
    }

    public function withWorkspaceKey(Workspace $workspace, string $providerName, Closure $callback): mixed
    {
        $configKey = "ai.providers.{$providerName}.key";
        $previousKey = config($configKey);
        $workspaceKey = $this->forWorkspace($workspace, $providerName);

        if (filled($workspaceKey)) {
            config([$configKey => $workspaceKey]);
        }

        Ai::forgetInstance($providerName);

        try {
            return $callback();
        } finally {
            config([$configKey => $previousKey]);
            Ai::forgetInstance($providerName);
        }
    }
}
