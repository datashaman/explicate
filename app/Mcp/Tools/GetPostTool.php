<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\FormatsMcpPayloads;
use App\Mcp\ExplicateContext;
use App\Mcp\ExplicateUris;
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

#[Name('get-post')]
#[Description('Get a post and its attachments for a topic inside the current workspace.')]
#[IsReadOnly]
#[IsIdempotent]
class GetPostTool extends Tool
{
    use FormatsMcpPayloads;

    public function __construct(protected ExplicateContext $context) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'topic_slug' => ['required', 'string'],
            'post_ulid' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $post = $this->context->postFor(
            $user,
            $validated['topic_slug'],
            $validated['post_ulid'],
        );
        $post->load(['topic.workspace', 'attachments', 'sender.user', 'sender.agent']);

        return Response::structured([
            'workspace' => $post->topic->workspace->only(['id', 'name', 'slug']),
            'topic' => [
                ...$post->topic->only(['id', 'name', 'slug']),
                'resource_uri' => ExplicateUris::topic($post->topic),
            ],
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
                ->description('The topic slug that owns the post.')
                ->required(),
            'post_ulid' => $schema->string()
                ->description('The post ULID to fetch.')
                ->required(),
        ];
    }
}
