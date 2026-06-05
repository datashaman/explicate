<?php

namespace App\Mcp\Tools;

use App\Mcp\TopicForgeContext;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List messages for a topic inside an accessible workspace.')]
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
            'workspace_slug' => ['nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $topic = $this->context->topicFor($user, $validated['topic_slug'], $validated['workspace_slug'] ?? null);

        $messages = $topic->messages()
            ->orderBy('title')
            ->get()
            ->map(fn ($message) => [
                'id' => $message->id,
                'title' => $message->title,
                'slug' => $message->slug,
                'status' => $message->status->value,
                'has_body' => filled($message->body),
            ])
            ->values()
            ->all();

        return Response::structured([
            'workspace' => $topic->workspace->only(['id', 'name', 'slug']),
            'topic' => $topic->only(['id', 'name', 'slug']),
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
            'workspace_slug' => $schema->string()
                ->description('Optional workspace slug. Defaults to the authenticated user\'s current workspace.')
                ->nullable(),
        ];
    }
}
