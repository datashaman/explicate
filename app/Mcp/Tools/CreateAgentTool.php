<?php

namespace App\Mcp\Tools;

use App\Actions\Agents\AgentToolCatalog;
use App\Actions\Agents\CreateAgent;
use App\Enums\Provider;
use App\Enums\ReasoningEffort;
use App\Mcp\ExplicateContext;
use App\Mcp\ExplicateUris;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create-agent')]
#[Description('Create an agent in the authenticated user\'s current workspace.')]
class CreateAgentTool extends Tool
{
    public function __construct(protected ExplicateContext $context) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'provider' => ['required', 'string', Rule::enum(Provider::class)],
            'model' => ['required', 'string', 'max:255'],
            'reasoning_effort' => ['nullable', 'string', Rule::enum(ReasoningEffort::class)],
            'prompt' => ['nullable', 'string'],
            'allowed_tools' => ['nullable', 'array'],
            'allowed_tools.*' => ['string', Rule::in(app(AgentToolCatalog::class)->names())],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $workspace = $this->context->workspaceFor($user);
        $agent = app(CreateAgent::class)->handle(
            workspace: $workspace,
            name: $validated['name'],
            provider: $validated['provider'],
            model: $validated['model'],
            reasoningEffort: $validated['reasoning_effort'] ?? null,
            prompt: $validated['prompt'] ?? null,
            allowedTools: app(AgentToolCatalog::class)->normalize($validated['allowed_tools'] ?? null),
        );

        return Response::structured([
            'workspace' => $workspace->only(['id', 'name', 'slug']),
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'slug' => $agent->slug,
                'resource_uri' => ExplicateUris::agent($agent),
            ],
            'latest_version' => [
                'version' => $agent->latestVersion?->version,
                'provider' => $agent->latestVersion?->provider->value,
                'model' => $agent->latestVersion?->model,
                'reasoning_effort' => $agent->latestVersion?->reasoning_effort?->value,
                'prompt' => $agent->latestVersion?->prompt,
                'allowed_tools' => app(AgentToolCatalog::class)->normalize($agent->latestVersion?->allowed_tools),
                'created_at' => $agent->latestVersion?->created_at?->toIso8601String(),
            ],
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
            'name' => $schema->string()
                ->description('The agent name.')
                ->required(),
            'provider' => $schema->string()
                ->description('The model provider.')
                ->enum(Provider::class)
                ->required(),
            'model' => $schema->string()
                ->description('The model identifier.')
                ->required(),
            'reasoning_effort' => $schema->string()
                ->description('Optional reasoning effort for models that support it.')
                ->enum(ReasoningEffort::class)
                ->nullable(),
            'prompt' => $schema->string()
                ->description('Optional system prompt for the agent.')
                ->nullable(),
            'allowed_tools' => $schema->array()
                ->description('Optional list of MCP tool names this agent version may call. Omit to allow all agent tools.')
                ->items($schema->string())
                ->nullable(),
        ];
    }
}
