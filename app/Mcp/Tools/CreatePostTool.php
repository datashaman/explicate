<?php

namespace App\Mcp\Tools;

use App\Actions\Posts\CreatePost;
use App\Enums\PostStatus;
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

#[Name('create-post')]
#[Description('Reply to an existing thread in the current workspace.')]
class CreatePostTool extends Tool
{
    public function __construct(protected ExplicateContext $context) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'thread' => ['required', 'string'],
            'body' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $thread = $this->context->threadFor($user, $validated['thread']);
        $post = app(CreatePost::class)->handle(
            thread: $thread,
            sender: $thread->workspace->principalForUser($user),
            body: $validated['body'],
            status: PostStatus::Published,
        );

        return Response::structured([
            'workspace' => $thread->workspace->only(['id', 'name', 'slug']),
            'thread' => [
                'id' => $thread->id,
                'title' => $thread->title,
                'slug' => $thread->slug,
                'resource_uri' => ExplicateUris::thread($thread),
            ],
            'post' => [
                'id' => $post->id,
                'ulid' => $post->ulid,
                'preview' => $post->preview(),
                'status' => $post->status->value,
                'sender_principal_id' => $post->sender_principal_id,
                'resource_uri' => ExplicateUris::post($post),
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
            'thread' => $schema->string()
                ->description('The thread slug or id to reply to.')
                ->required(),
            'body' => $schema->string()
                ->description('The reply body.')
                ->required(),
        ];
    }
}
