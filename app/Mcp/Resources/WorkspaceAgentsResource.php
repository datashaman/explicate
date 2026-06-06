<?php

namespace App\Mcp\Resources;

use App\Mcp\Resources\Concerns\HandlesResourceExceptions;
use App\Mcp\TopicForgeContext;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Description('List agents for an accessible workspace.')]
class WorkspaceAgentsResource extends Resource implements HasUriTemplate
{
    use HandlesResourceExceptions;

    public function __construct(protected TopicForgeContext $context) {}

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('topic-forge://workspaces/{workspace}/agents');
    }

    public function handle(Request $request): Response
    {
        return $this->guardResource(function () use ($request): Response {
            /** @var User $user */
            $user = $this->context->requireUser($request->user());
            $workspace = $this->context->workspaceFor($user, (string) $request->get('workspace'));

            $agents = $workspace->agents()
                ->with(['latestVersion', 'topics'])
                ->get()
                ->map(fn ($agent) => [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'slug' => $agent->slug,
                    'topics_count' => $agent->topics->count(),
                    'latest_version' => $agent->latestVersion?->version,
                    'latest_model' => $agent->latestVersion?->model,
                    'resource_uri' => "topic-forge://workspaces/{$workspace->slug}/agents/{$agent->slug}",
                ])
                ->values()
                ->all();

            return Response::json([
                'workspace' => $workspace->only(['id', 'name', 'slug']),
                'agents' => $agents,
            ]);
        });
    }
}
