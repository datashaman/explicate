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

#[Description('Read a message with its attachments from an accessible workspace topic.')]
class MessageResource extends Resource implements HasUriTemplate
{
    use HandlesResourceExceptions;

    public function __construct(protected TopicForgeContext $context) {}

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('topic-forge://workspaces/{workspace}/topics/{topic}/messages/{message}');
    }

    public function handle(Request $request): Response
    {
        return $this->guardResource(function () use ($request): Response {
            /** @var User $user */
            $user = $this->context->requireUser($request->user());
            $message = $this->context->messageFor(
                $user,
                (string) $request->get('topic'),
                (string) $request->get('message'),
                (string) $request->get('workspace'),
            );
            $message->load(['topic.workspace', 'attachments']);

            return Response::json([
                'workspace' => $message->topic->workspace->only(['id', 'name', 'slug']),
                'topic' => [
                    ...$message->topic->only(['id', 'name', 'slug']),
                    'resource_uri' => "topic-forge://workspaces/{$message->topic->workspace->slug}/topics/{$message->topic->slug}",
                ],
                'message' => [
                    'id' => $message->id,
                    'title' => $message->title,
                    'slug' => $message->slug,
                    'status' => $message->status->value,
                    'body' => $message->body,
                    'resource_uri' => "topic-forge://workspaces/{$message->topic->workspace->slug}/topics/{$message->topic->slug}/messages/{$message->slug}",
                ],
                'attachments' => $message->attachments
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
