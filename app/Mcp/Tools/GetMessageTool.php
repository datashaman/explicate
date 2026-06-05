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

#[Description('Get a message and its attachments for a topic inside an accessible workspace.')]
#[IsReadOnly]
#[IsIdempotent]
class GetMessageTool extends Tool
{
    public function __construct(protected TopicForgeContext $context) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'topic_slug' => ['required', 'string'],
            'message_slug' => ['required', 'string'],
            'workspace_slug' => ['nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $message = $this->context->messageFor(
            $user,
            $validated['topic_slug'],
            $validated['message_slug'],
            $validated['workspace_slug'] ?? null,
        );
        $message->load(['topic.workspace', 'attachments']);

        return Response::structured([
            'workspace' => $message->topic->workspace->only(['id', 'name', 'slug']),
            'topic' => $message->topic->only(['id', 'name', 'slug']),
            'message' => [
                'id' => $message->id,
                'title' => $message->title,
                'slug' => $message->slug,
                'status' => $message->status->value,
                'body' => $message->body,
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
                ->description('The topic slug that owns the message.')
                ->required(),
            'message_slug' => $schema->string()
                ->description('The message slug to fetch.')
                ->required(),
            'workspace_slug' => $schema->string()
                ->description('Optional workspace slug. Defaults to the authenticated user\'s current workspace.')
                ->nullable(),
        ];
    }
}
