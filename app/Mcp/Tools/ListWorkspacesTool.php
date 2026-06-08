<?php

namespace App\Mcp\Tools;

use App\Mcp\ExplicateContext;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-workspaces')]
#[Description('List the authenticated user\'s accessible workspaces in the current team.')]
#[IsReadOnly]
#[IsIdempotent]
class ListWorkspacesTool extends Tool
{
    public function __construct(protected ExplicateContext $context) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $this->context->requireUser($request->user());

        $workspaces = $user->toUserWorkspaces()
            ->map(fn ($workspace) => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'is_current' => $workspace->isCurrent,
            ])
            ->values()
            ->all();

        return Response::structured([
            'team' => $user->currentTeam?->only(['id', 'name', 'slug']),
            'workspaces' => $workspaces,
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
