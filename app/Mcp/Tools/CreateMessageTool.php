<?php

namespace App\Mcp\Tools;

use App\Enums\MessageStatus;
use App\Mcp\TopicForgeContext;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a message inside a topic in an accessible workspace.')]
class CreateMessageTool extends Tool
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
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(MessageStatus::cases(), 'value'))],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $topic = $this->context->topicFor($user, $validated['topic_slug'], $validated['workspace_slug'] ?? null);

        $message = new Message([
            'title' => $validated['title'],
            'body' => $validated['body'] ?? null,
            'status' => $validated['status'] ?? MessageStatus::Draft->value,
        ]);

        $topic->messages()->save($message);

        return Response::structured([
            'workspace' => $topic->workspace->only(['id', 'name', 'slug']),
            'topic' => $topic->only(['id', 'name', 'slug']),
            'message' => [
                'id' => $message->id,
                'title' => $message->title,
                'slug' => $message->slug,
                'status' => $message->status->value,
            ],
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
                ->description('The topic slug the message should be created in.')
                ->required(),
            'workspace_slug' => $schema->string()
                ->description('Optional workspace slug. Defaults to the authenticated user\'s current workspace.')
                ->nullable(),
            'title' => $schema->string()
                ->description('The message title.')
                ->required(),
            'body' => $schema->string()
                ->description('Optional message body.')
                ->nullable(),
            'status' => $schema->string()
                ->description('Optional message status.')
                ->enum(MessageStatus::class)
                ->nullable(),
        ];
    }
}
