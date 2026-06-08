<?php

namespace App\Mcp\Tools;

use App\Enums\PostStatus;
use App\Mcp\Concerns\FormatsMcpPayloads;
use App\Mcp\TopicForgeContext;
use App\Mcp\TopicForgeUris;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('update-post')]
#[Description('Update the body or status of a post the authenticated user sent. Only the original sender can edit a post.')]
class UpdatePostTool extends Tool
{
    use FormatsMcpPayloads;

    public function __construct(protected TopicForgeContext $context) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'topic_slug' => ['required', 'string'],
            'post_ulid' => ['required', 'string'],
            'body' => ['nullable', 'string'],
            'status' => ['nullable', 'string', Rule::enum(PostStatus::class)],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $post = $this->context->postFor($user, $validated['topic_slug'], $validated['post_ulid']);
        $sender = $post->topic->workspace->principalForUser($user);

        if ($post->sender_principal_id !== $sender->id) {
            throw new AuthorizationException('Only the original sender can edit this post.');
        }

        $attributes = array_filter([
            'body' => $validated['body'] ?? null,
            'status' => isset($validated['status']) ? PostStatus::from($validated['status']) : null,
        ], fn ($value) => $value !== null);

        $post->update($attributes);

        return Response::structured([
            'workspace' => $post->topic->workspace->only(['id', 'name', 'slug']),
            'topic' => [
                ...$post->topic->only(['id', 'name', 'slug']),
                'resource_uri' => TopicForgeUris::topic($post->topic),
            ],
            'post' => $this->postPayload($post->fresh()),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'topic_slug' => $schema->string()
                ->description('The topic slug that owns the post.')
                ->required(),
            'post_ulid' => $schema->string()
                ->description('The ULID of the post to update.')
                ->required(),
            'body' => $schema->string()
                ->description('New post body. Omit to leave unchanged.')
                ->nullable(),
            'status' => $schema->string()
                ->description('New post status. Omit to leave unchanged.')
                ->enum(PostStatus::class)
                ->nullable(),
        ];
    }
}
