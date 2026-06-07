<?php

namespace App\Mcp\Resources;

use App\Mcp\Resources\Concerns\HandlesResourceExceptions;
use App\Mcp\TopicForgeContext;
use App\Mcp\TopicForgeUris;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Description('Read an agent with its attached topics and version history from an accessible workspace.')]
class AgentResource extends Resource implements HasUriTemplate
{
    use HandlesResourceExceptions;

    public function __construct(protected TopicForgeContext $context) {}

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate(TopicForgeUris::AgentTemplate);
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
            $agent->load(['workspace', 'topics', 'latestVersion', 'versions']);

            return Response::json([
                'workspace' => $agent->workspace->only(['id', 'name', 'slug']),
                'agent' => [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'slug' => $agent->slug,
                    'latest_version' => $agent->latestVersion?->version,
                    'latest_model' => $agent->latestVersion?->model,
                    'resource_uri' => TopicForgeUris::agent($agent),
                    'tasks_resource_uri' => TopicForgeUris::agentTasks($agent),
                ],
                'topics' => $agent->topics
                    ->map(fn ($topic) => [
                        'id' => $topic->id,
                        'name' => $topic->name,
                        'slug' => $topic->slug,
                        'resource_uri' => TopicForgeUris::topic($topic),
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
        });
    }
}
