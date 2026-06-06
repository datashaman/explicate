<?php

namespace App\Mcp\Tools;

use App\Mcp\TopicForgeContext;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('switch-workspace')]
#[Description('Switch the authenticated user\'s current Topic Forge workspace context.')]
class SwitchWorkspaceTool extends Tool
{
    public function __construct(protected TopicForgeContext $context) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace_slug' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $workspace = $this->context->workspaceFor($user, $validated['workspace_slug']);

        $user->switchWorkspace($workspace);

        return Response::structured([
            'team' => $user->currentTeam?->only(['id', 'name', 'slug']),
            'workspace' => [
                ...$workspace->only(['id', 'name', 'slug']),
                'is_current' => true,
                'topics_resource_uri' => "topic-forge://workspaces/{$workspace->slug}/topics",
                'agents_resource_uri' => "topic-forge://workspaces/{$workspace->slug}/agents",
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
            'workspace_slug' => $schema->string()
                ->description('The workspace slug to set as the current Topic Forge context.')
                ->required(),
        ];
    }
}
