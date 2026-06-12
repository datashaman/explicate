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

#[Description('Read an agent with its version history from an accessible workspace.')]
class AgentResource extends Resource implements HasUriTemplate
{
    use HandlesResourceExceptions;

    public function __construct(protected ExplicateContext $context) {}

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate(ExplicateUris::AgentTemplate);
    }

    public function handle(Request $request): Response
    {
        return $this->guardResource(function () use ($request): Response {
            /** @var User $user */
            $user = $this->context->requireUser($request->user());
            $agent = $this->context->agentFor(
                $user,
                (string) $request->get('agent'),
                (string) $request->get('workspace'),
            );
            $agent->load(['workspace', 'latestVersion', 'versions']);

            return Response::json([
                'workspace' => $agent->workspace->only(['id', 'name', 'slug']),
                'agent' => [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'slug' => $agent->slug,
                    'latest_version' => $agent->latestVersion?->version,
                    'latest_model' => $agent->latestVersion?->model,
                    'resource_uri' => ExplicateUris::agent($agent),
                    'tasks_resource_uri' => ExplicateUris::agentTasks($agent),
                ],
                'versions' => $agent->versions
                    ->sortByDesc('version')
                    ->values()
                    ->map(fn ($version) => [
                        'version' => $version->version,
                        'provider' => $version->provider->value,
                        'model' => $version->model,
                        'reasoning_effort' => $version->reasoning_effort?->value,
                        'prompt' => $version->prompt,
                        'allowed_tools' => app(AgentToolCatalog::class)->normalize($version->allowed_tools),
                        'created_at' => $version->created_at?->toIso8601String(),
                    ])
                    ->all(),
            ]);
        });
    }
}
