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

#[Description('Read a post with its attachments from an accessible workspace.')]
class PostResource extends Resource implements HasUriTemplate
{
    use FormatsMcpPayloads;
    use HandlesResourceExceptions;

    public function __construct(protected ExplicateContext $context) {}

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate(ExplicateUris::PostTemplate);
    }

    public function handle(Request $request): Response
    {
        return $this->guardResource(function () use ($request): Response {
            /** @var User $user */
            $user = $this->context->requireUser($request->user());
            $post = $this->context->postFor(
                $user,
                (string) $request->get('post'),
                (string) $request->get('workspace'),
            );
            $post->load(['thread.workspace', 'thread.topic', 'attachments', 'sender.user', 'sender.agent']);

            return Response::json([
                'workspace' => $post->thread->workspace->only(['id', 'name', 'slug']),
                'thread' => $this->threadSummaryPayload($post->thread),
                'post' => $this->postPayload($post),
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
