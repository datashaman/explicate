<?php

namespace App\Mcp\Resources;

use App\Actions\Agents\AgentToolCatalog;
use App\Mcp\ExplicateContext;
use App\Mcp\ExplicateUris;
use App\Mcp\Resources\Concerns\HandlesResourceExceptions;
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

    public function __construct(protected ExplicateContext $context) {}

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate(ExplicateUris::WorkspaceAgentsTemplate);
    }

    public function handle(Request $request): Response
    {
        return $this->guardResource(function () use ($request): Response {
            /** @var User $user */
            $user = $this->context->requireUser($request->user());
            $workspace = $this->context->workspaceFor($user, (string) $request->get('workspace'));

            $agents = $workspace->agents()
                ->with('latestVersion')
                ->get()
                ->map(fn ($agent) => [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'slug' => $agent->slug,
                    'latest_version' => $agent->latestVersion?->version,
                    'latest_model' => $agent->latestVersion?->model,
                    'allowed_tools' => app(AgentToolCatalog::class)->normalize($agent->latestVersion?->allowed_tools),
                    'resource_uri' => ExplicateUris::agent($agent),
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
