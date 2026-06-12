<?php

namespace App\Actions\Agents;

use App\Enums\Provider;
use App\Enums\ReasoningEffort;
use App\Models\Agent;
use App\Models\AgentVersion;
use App\Services\AiProviderKeyService;
use Illuminate\Validation\ValidationException;

class CreateAgentVersion
{
    public function __construct(private readonly AiProviderKeyService $providerKeys) {}

    public function handle(
        Agent $agent,
        string $provider,
        string $model,
        ?string $reasoningEffort,
        ?string $prompt,
        ?array $allowedTools = null,
    ): AgentVersion {
        $agent->loadMissing('workspace');

        if (! $this->providerKeys->hasKeyForWorkspace($agent->workspace, $provider)) {
            $label = Provider::tryFrom($provider)?->label() ?? $provider;

            throw ValidationException::withMessages([
                'provider' => "The {$label} provider cannot be used until an API key is configured for this workspace or team.",
            ]);
        }

        $providerEnum = Provider::from($provider);
        $supportsReasoningEffort = $providerEnum->supportsReasoningEffort($model);
        $reasoningEffortEnum = match (true) {
            ! $supportsReasoningEffort => null,
            filled($reasoningEffort) => ReasoningEffort::from($reasoningEffort),
            default => ReasoningEffort::Medium,
        };

        return $agent->versions()->create([
            'provider' => $provider,
            'model' => $model,
            'reasoning_effort' => $reasoningEffortEnum,
            'prompt' => $prompt ?: null,
            'allowed_tools' => $allowedTools,
        ]);
    }
}
