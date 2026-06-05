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

#[Description('List topics for an accessible workspace, defaulting to the authenticated user\'s current workspace.')]
#[IsReadOnly]
#[IsIdempotent]
class ListTopicsTool extends Tool
{
    public function __construct(protected TopicForgeContext $context) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace_slug' => ['nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $workspace = $this->context->workspaceFor($user, $validated['workspace_slug'] ?? null);

        $topics = $workspace->topics()
            ->withCount('messages')
            ->get()
            ->map(fn ($topic) => [
                'id' => $topic->id,
                'name' => $topic->name,
                'slug' => $topic->slug,
                'messages_count' => $topic->messages_count,
                'resource_uri' => "topic-forge://workspaces/{$workspace->slug}/topics/{$topic->slug}",
            ])
            ->values()
            ->all();

        return Response::structured([
            'workspace' => $workspace->only(['id', 'name', 'slug']),
            'topics' => $topics,
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
            'workspace_slug' => $schema->string()
                ->description('Optional workspace slug. Defaults to the authenticated user\'s current workspace.')
                ->nullable(),
        ];
    }
}
