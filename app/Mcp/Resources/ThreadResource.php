<?php

namespace App\Mcp\Resources;

use App\Mcp\Concerns\FormatsMcpPayloads;
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

#[Description('Read a thread and its posts from an accessible workspace.')]
class ThreadResource extends Resource implements HasUriTemplate
{
    use FormatsMcpPayloads;
    use HandlesResourceExceptions;

    public function __construct(protected ExplicateContext $context) {}

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate(ExplicateUris::ThreadTemplate);
    }

    public function handle(Request $request): Response
    {
        return $this->guardResource(function () use ($request): Response {
            /** @var User $user */
            $user = $this->context->requireUser($request->user());
            $thread = $this->context->threadFor(
                $user,
                (string) $request->get('thread'),
                (string) $request->get('workspace'),
            );
            $thread->load(['workspace', 'topic', 'latestPost.sender.user', 'latestPost.sender.agent']);

            return Response::json([
                'workspace' => $thread->workspace->only(['id', 'name', 'slug']),
                'thread' => $this->threadSummaryPayload($thread),
                'posts' => $thread->conversationPosts()
                    ->map(fn ($post) => $this->postPayload($post))
                    ->values()
                    ->all(),
            ]);
        });
    }
}
