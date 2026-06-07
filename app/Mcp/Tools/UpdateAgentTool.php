<?php

namespace App\Mcp\Tools;

use App\Actions\Agents\CreateAgentVersion;
use App\Enums\Provider;
use App\Enums\ReasoningEffort;
use App\Mcp\TopicForgeContext;
use App\Mcp\TopicForgeUris;
use App\Models\AgentVersion;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('update-agent')]
#[Description('Update an agent in the authenticated user\'s current workspace.')]
class UpdateAgentTool extends Tool
{
    public function __construct(protected TopicForgeContext $context) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'agent_slug' => ['required', 'string'],
            'name' => ['sometimes', 'filled', 'string', 'max:255'],
            'provider' => ['sometimes', 'filled', 'string', Rule::enum(Provider::class)],
            'model' => ['sometimes', 'filled', 'string', 'max:255'],
            'reasoning_effort' => ['sometimes', 'nullable', 'string', Rule::enum(ReasoningEffort::class)],
            'prompt' => ['sometimes', 'nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $agent = $this->context->agentFor($user, $validated['agent_slug']);
        $agent->load(['workspace', 'latestVersion']);

        if (array_key_exists('name', $validated)) {
            $agent->update(['name' => $validated['name']]);
            $agent->refresh();
        }

        if ($this->hasVersionInput($validated)) {
            $latestVersion = $agent->latestVersion;
            $provider = $validated['provider'] ?? $latestVersion?->provider->value;
            $model = $validated['model'] ?? $latestVersion?->model;

            if ($provider === null || $model === null) {
                return Response::error('Updating an agent version requires provider and model when the agent does not have an existing version.');
            }

            app(CreateAgentVersion::class)->handle(
                agent: $agent,
                provider: $provider,
                model: $model,
                reasoningEffort: array_key_exists('reasoning_effort', $validated)
                    ? $validated['reasoning_effort']
                    : $latestVersion?->reasoning_effort?->value,
                prompt: array_key_exists('prompt', $validated)
                    ? $validated['prompt']
                    : $latestVersion?->prompt,
            );

            $agent->load('latestVersion');
        }

        return Response::structured([
            'workspace' => $agent->workspace->only(['id', 'name', 'slug']),
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'slug' => $agent->slug,
                'resource_uri' => TopicForgeUris::agent($agent),
            ],
            'latest_version' => $this->versionPayload($agent->latestVersion),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_slug' => $schema->string()
                ->description('The current slug of the agent to update.')
                ->required(),
            'name' => $schema->string()
                ->description('Optional new agent name. Renaming the agent may change its slug.')
                ->nullable(),
            'provider' => $schema->string()
                ->description('Optional model provider for a new agent version.')
                ->enum(Provider::class)
                ->nullable(),
            'model' => $schema->string()
                ->description('Optional model identifier for a new agent version.')
                ->nullable(),
            'reasoning_effort' => $schema->string()
                ->description('Optional reasoning effort for a new agent version.')
                ->enum(ReasoningEffort::class)
                ->nullable(),
            'prompt' => $schema->string()
                ->description('Optional system prompt for a new agent version.')
                ->nullable(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function hasVersionInput(array $validated): bool
    {
        return array_any(
            ['provider', 'model', 'reasoning_effort', 'prompt'],
            fn (string $field): bool => array_key_exists($field, $validated),
        );
    }

    /**
     * @return array{version: int|null, provider: string|null, model: string|null, reasoning_effort: string|null, prompt: string|null, created_at: string|null}
     */
    private function versionPayload(?AgentVersion $version): array
    {
        return [
            'version' => $version?->version,
            'provider' => $version?->provider->value,
            'model' => $version?->model,
            'reasoning_effort' => $version?->reasoning_effort?->value,
            'prompt' => $version?->prompt,
            'created_at' => $version?->created_at?->toIso8601String(),
        ];
    }
}
