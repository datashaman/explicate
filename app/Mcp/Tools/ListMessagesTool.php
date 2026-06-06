<?php

namespace App\Mcp\Tools;

use App\Mcp\TopicForgeContext;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-messages')]
#[Description('List messages for a topic inside the current workspace.')]
#[IsReadOnly]
#[IsIdempotent]
class ListMessagesTool extends Tool
{
    public function __construct(protected TopicForgeContext $context) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'topic_slug' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $topic = $this->context->topicFor($user, $validated['topic_slug']);

        $messages = $topic->messages()
            ->whereNull('recipient_user_id')
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

        return Response::structured([
            'workspace' => $topic->workspace->only(['id', 'name', 'slug']),
            'topic' => [
                ...$topic->only(['id', 'name', 'slug']),
                'resource_uri' => "topic-forge://workspaces/{$topic->workspace->slug}/topics/{$topic->slug}",
            ],
            'messages' => $messages,
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'topic_slug' => $schema->string()
                ->description('The topic slug whose messages should be listed.')
                ->required(),
        ];
    }
}
