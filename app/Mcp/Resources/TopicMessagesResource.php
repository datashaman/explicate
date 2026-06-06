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

#[Description('List messages for a topic inside an accessible workspace.')]
class TopicMessagesResource extends Resource implements HasUriTemplate
{
    use HandlesResourceExceptions;

    public function __construct(protected TopicForgeContext $context) {}

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('topic-forge://workspaces/{workspace}/topics/{topic}/messages');
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

            $messages = $topic->messages()
                ->whereNull('recipient_principal_id')
                ->orderBy('title')
                ->get()
                ->map(fn ($message) => [
                    'id' => $message->id,
                    'title' => $message->title,
                    'slug' => $message->slug,
                    'status' => $message->status->value,
                    'has_body' => filled($message->body),
                    'resource_uri' => "topic-forge://workspaces/{$topic->workspace->slug}/topics/{$topic->slug}/messages/{$message->slug}",
                ])
                ->values()
                ->all();

            return Response::json([
                'workspace' => $topic->workspace->only(['id', 'name', 'slug']),
                'topic' => [
                    ...$topic->only(['id', 'name', 'slug']),
                    'resource_uri' => "topic-forge://workspaces/{$topic->workspace->slug}/topics/{$topic->slug}",
                ],
                'messages' => $messages,
            ]);
        });
    }
}
