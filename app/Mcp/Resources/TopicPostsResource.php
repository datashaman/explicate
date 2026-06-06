<?php

namespace App\Mcp\Resources;

use App\Mcp\Concerns\FormatsMcpPayloads;
use App\Mcp\Resources\Concerns\HandlesResourceExceptions;
use App\Mcp\TopicForgeContext;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Description('List posts for a topic inside an accessible workspace.')]
class TopicPostsResource extends Resource implements HasUriTemplate
{
    use FormatsMcpPayloads;
    use HandlesResourceExceptions;

    public function __construct(protected TopicForgeContext $context) {}

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('topic-forge://workspaces/{workspace}/topics/{topic}/posts');
    }

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

            $posts = $topic->posts()
                ->with(['topic.workspace', 'sender.user', 'sender.agent', 'recipient.user', 'recipient.agent'])
                ->orderBy('title')
                ->get()
                ->map(fn ($post) => $this->postPayload($post))
                ->values()
                ->all();

            return Response::json([
                'workspace' => $topic->workspace->only(['id', 'name', 'slug']),
                'topic' => [
                    ...$topic->only(['id', 'name', 'slug']),
                    'resource_uri' => "topic-forge://workspaces/{$topic->workspace->slug}/topics/{$topic->slug}",
                ],
                'posts' => $posts,
            ]);
        });
    }
}
