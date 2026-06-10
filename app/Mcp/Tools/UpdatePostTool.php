<?php

namespace App\Mcp\Tools;

use App\Enums\PostStatus;
use App\Mcp\Concerns\FormatsMcpPayloads;
use App\Mcp\ExplicateContext;
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

    public function __construct(protected ExplicateContext $context) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'post_ulid' => ['required', 'string'],
            'body' => ['nullable', 'string'],
            'status' => ['nullable', 'string', Rule::enum(PostStatus::class)],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $post = $this->context->postFor($user, $validated['post_ulid']);
        $post->loadMissing('thread.workspace');
        $sender = $post->thread->workspace->principalForUser($user);

        if ($post->sender_principal_id !== $sender->id) {
            throw new AuthorizationException('Only the original sender can edit this post.');
        }

        $attributes = array_filter([
            'body' => $validated['body'] ?? null,
            'status' => isset($validated['status']) ? PostStatus::from($validated['status']) : null,
        ], fn ($value) => $value !== null);

        $post->update($attributes);
        $post->refresh()->load(['thread.workspace', 'thread.topic', 'sender.user', 'sender.agent']);

        return Response::structured([
            'workspace' => $post->thread->workspace->only(['id', 'name', 'slug']),
            'thread' => $this->threadSummaryPayload($post->thread),
            'post' => $this->postPayload($post),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
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
