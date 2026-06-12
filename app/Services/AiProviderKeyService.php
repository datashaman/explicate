<?php

namespace App\Services;

use App\Enums\Provider;
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

    public function hasKeyForWorkspace(Workspace $workspace, string $providerName): bool
    {
        return filled($this->forWorkspace($workspace, $providerName))
            || filled(config("ai.providers.{$providerName}.key"));
    }

    /**
     * @return list<array{provider: string, label: string, models: list<string>, source: string}>
     */
    public function availableProvidersForWorkspace(Workspace $workspace): array
    {
        return collect(Provider::cases())
            ->map(fn (Provider $provider): ?array => match ($source = $this->keySourceForWorkspace($workspace, $provider->value)) {
                null => null,
                default => [
                    'provider' => $provider->value,
                    'label' => $provider->label(),
                    'models' => $provider->models(),
                    'source' => $source,
                ],
            })
            ->filter()
            ->values()
            ->all();
    }

    public function keySourceForWorkspace(Workspace $workspace, string $providerName): ?string
    {
        $workspaceProviderKey = ProviderKey::query()
            ->where('provider', $providerName)
            ->where('workspace_id', $workspace->id)
            ->first();

        if (filled($workspaceProviderKey?->api_key)) {
            return 'workspace';
        }

        $teamProviderKey = ProviderKey::query()
            ->where('provider', $providerName)
            ->where('team_id', $workspace->team_id)
            ->whereNull('workspace_id')
            ->first();

        if (filled($teamProviderKey?->api_key)) {
            return 'team';
        }

        if (filled(config("ai.providers.{$providerName}.key"))) {
            return 'config';
        }

        return null;
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
