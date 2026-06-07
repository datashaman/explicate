<?php

namespace App\Mcp\Resources;

use App\Mcp\Concerns\FormatsMcpPayloads;
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

#[Description('Read a post with its attachments from an accessible workspace topic.')]
class PostResource extends Resource implements HasUriTemplate
{
    use FormatsMcpPayloads;
    use HandlesResourceExceptions;

    public function __construct(protected TopicForgeContext $context) {}

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate(TopicForgeUris::PostTemplate);
    }

    public function handle(Request $request): Response
    {
        return $this->guardResource(function () use ($request): Response {
            /** @var User $user */
            $user = $this->context->requireUser($request->user());
            $post = $this->context->postFor(
                $user,
                (string) $request->get('topic'),
                (string) $request->get('post'),
                (string) $request->get('workspace'),
            );
            $post->load(['topic.workspace', 'attachments', 'sender.user', 'sender.agent', 'recipient.user', 'recipient.agent']);

            return Response::json([
                'workspace' => $post->topic->workspace->only(['id', 'name', 'slug']),
                'topic' => [
                    ...$post->topic->only(['id', 'name', 'slug']),
                    'resource_uri' => TopicForgeUris::topic($post->topic),
                ],
                'post' => $this->postPayload($post, includeBody: true),
                'attachments' => $post->attachments
                    ->map(fn ($attachment) => [
                        'id' => $attachment->id,
                        'filename' => $attachment->filename,
                        'mime_type' => $attachment->mime_type,
                        'size' => $attachment->size,
                    ])
                    ->values()
                    ->all(),
            ]);
        });
    }
}
