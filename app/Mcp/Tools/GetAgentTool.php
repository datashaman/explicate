<?php

namespace App\Mcp\Tools;

use App\Mcp\TopicForgeContext;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get an agent with its topic attachments and version history inside an accessible workspace.')]
#[IsReadOnly]
#[IsIdempotent]
class GetAgentTool extends Tool
{
    public function __construct(protected TopicForgeContext $context) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'agent_slug' => ['required', 'string'],
            'workspace_slug' => ['nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $agent = $this->context->agentFor($user, $validated['agent_slug'], $validated['workspace_slug'] ?? null);
        $agent->load(['workspace', 'topics', 'latestVersion', 'versions']);

        return Response::structured([
            'workspace' => $agent->workspace->only(['id', 'name', 'slug']),
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'slug' => $agent->slug,
                'latest_version' => $agent->latestVersion?->version,
                'latest_model' => $agent->latestVersion?->model,
            ],
            'topics' => $agent->topics
                ->map(fn ($topic) => [
                    'id' => $topic->id,
                    'name' => $topic->name,
                    'slug' => $topic->slug,
                ])
                ->values()
                ->all(),
            'versions' => $agent->versions
                ->sortByDesc('version')
                ->values()
                ->map(fn ($version) => [
                    'version' => $version->version,
                    'provider' => $version->provider->value,
                    'model' => $version->model,
                    'reasoning_effort' => $version->reasoning_effort?->value,
                    'prompt' => $version->prompt,
                    'created_at' => $version->created_at?->toIso8601String(),
                ])
                ->all(),
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
                ->description('The agent slug to fetch.')
                ->required(),
            'workspace_slug' => $schema->string()
                ->description('Optional workspace slug. Defaults to the authenticated user\'s current workspace.')
                ->nullable(),
        ];
    }
}
