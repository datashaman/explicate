<?php

namespace App\Mcp\Resources;

use App\Mcp\Resources\Concerns\HandlesResourceExceptions;
use App\Mcp\TopicForgeContext;
use App\Mcp\TopicForgeUris;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('List the authenticated user\'s accessible workspaces in the current team.')]
#[Uri(TopicForgeUris::Workspaces)]
#[MimeType('application/json')]
class WorkspacesResource extends Resource
{
    use HandlesResourceExceptions;

    public function __construct(protected TopicForgeContext $context) {}

    /**
     * Handle the resource request.
     */
    public function handle(Request $request): Response
    {
        return $this->guardResource(function () use ($request): Response {
            /** @var User $user */
            $user = $this->context->requireUser($request->user());

            return Response::json([
                'team' => $user->currentTeam?->only(['id', 'name', 'slug']),
                'resource_uri' => TopicForgeUris::Workspaces,
                'workspaces' => $user->toUserWorkspaces()
                    ->map(fn ($workspace) => [
                        'id' => $workspace->id,
                        'name' => $workspace->name,
                        'slug' => $workspace->slug,
                        'is_current' => $workspace->isCurrent,
                        'topics_resource_uri' => TopicForgeUris::workspaceTopics($workspace),
                        'agents_resource_uri' => TopicForgeUris::workspaceAgents($workspace),
                    ])
                    ->values()
                    ->all(),
            ]);
        });
    }
}
