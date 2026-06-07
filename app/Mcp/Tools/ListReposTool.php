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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-repos')]
#[Description('List the git repositories connected to the current workspace.')]
#[IsReadOnly]
#[IsIdempotent]
class ListReposTool extends Tool
{
    public function __construct(protected TopicForgeContext $context) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $workspace = $this->context->workspaceFor($user);

        $repos = $workspace->repositories->map(fn ($repo) => [
            'id' => $repo->id,
            'name' => $repo->name,
            'url' => $repo->url,
            'branch' => $repo->branch,
            'path' => $repo->localPath(),
            'cloned' => $repo->isCloned(),
        ])->values()->all();

        return Response::structured([
            'workspace' => $workspace->only(['id', 'name', 'slug']),
            'repositories' => $repos,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
