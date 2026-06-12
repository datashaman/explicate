<?php

namespace App\Services;

use App\Models\ProviderKey;
use App\Models\Workspace;
use Closure;
use Illuminate\Support\Arr;
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
        $providerConfigKey = "ai.providers.{$providerName}";
        $previousProviderConfig = config($providerConfigKey);
        $workspaceKey = $this->forWorkspace($workspace, $providerName);

        if (filled($workspaceKey)) {
            config([$configKey => $workspaceKey]);
        }

        config([$providerConfigKey => $this->normalizeProviderConfig($providerName, config($providerConfigKey, []))]);

        Ai::forgetInstance($providerName);

        try {
            return $callback();
        } finally {
            config([$providerConfigKey => $previousProviderConfig]);
            config([$configKey => $previousKey]);
            Ai::forgetInstance($providerName);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizeProviderConfig(string $providerName, array $config): array
    {
        if ($providerName !== 'anthropic' || ! is_array($config['anthropic_beta'] ?? null)) {
            return $config;
        }

        $config['anthropic_beta'] = collect(Arr::flatten($config['anthropic_beta']))
            ->filter(fn (mixed $value): bool => is_scalar($value) || $value instanceof \Stringable)
            ->map(fn (mixed $value): string => (string) $value)
            ->implode(',');

        return $config;
    }
}
