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

#[Description('Read a topic with its posts and attached agents from an accessible workspace.')]
class TopicResource extends Resource implements HasUriTemplate
{
    use HandlesResourceExceptions;

    public function __construct(protected TopicForgeContext $context) {}

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate(TopicForgeUris::TopicTemplate);
    }

    /**
     * Handle the resource request.
     */
    public function handle(Request $request): Response
    {
        return $this->guardResource(function () use ($request): Response {
            /** @var User $user */
            $user = $this->context->requireUser($request->user());
            $topic = $this->context->topicFor(
                $user,
                (string) $request->get('topic'),
                (string) $request->get('workspace'),
            );

            $topic->load(['workspace', 'agents.latestVersion']);

            return Response::json([
                'workspace' => $topic->workspace->only(['id', 'name', 'slug']),
                'topic' => [
                    ...$topic->only(['id', 'name', 'slug']),
                    'resource_uri' => TopicForgeUris::topic($topic),
                ],
                'agents' => $topic->agents
                    ->map(fn ($agent) => [
                        'id' => $agent->id,
                        'name' => $agent->name,
                        'slug' => $agent->slug,
                        'latest_version' => $agent->latestVersion?->version,
                        'latest_model' => $agent->latestVersion?->model,
                        'resource_uri' => TopicForgeUris::agent($agent),
                    ])
                    ->values()
                    ->all(),
                'posts' => $topic->posts()
                    ->get()
                    ->map(fn ($post) => [
                        'id' => $post->id,
                        'ulid' => $post->ulid,
                        'preview' => $post->preview(),
                        'status' => $post->status->value,
                        'body' => $post->body,
                        'resource_uri' => TopicForgeUris::post($post),
                    ])
                    ->values()
                    ->all(),
            ]);
        });
    }
}
