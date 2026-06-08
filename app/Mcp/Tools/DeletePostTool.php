<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\FormatsMcpPayloads;
use App\Mcp\ExplicateContext;
use App\Mcp\ExplicateUris;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete-post')]
#[Description('Delete one of the authenticated user\'s posts inside a topic in the current workspace.')]
#[IsDestructive]
class DeletePostTool extends Tool
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
        $sender = $post->topic->workspace->principalForUser($user);

        if ($post->sender_principal_id !== $sender->id) {
            throw new AuthorizationException('Only the post sender can delete this post.');
        }

        $post->load(['topic.workspace', 'sender.user', 'sender.agent']);
        $payload = $this->postSummaryPayload($post);

        DB::transaction(function () use ($post): void {
            $post->attachments()->delete();
            $post->agentTasks()->get()->each->delete();
            $post->delete();
        });

        return Response::structured([
            'workspace' => $post->topic->workspace->only(['id', 'name', 'slug']),
            'topic' => [
                ...$post->topic->only(['id', 'name', 'slug']),
                'resource_uri' => ExplicateUris::topic($post->topic),
            ],
            'post' => $payload,
            'deleted' => true,
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
                ->description('The post ULID to delete.')
                ->required(),
        ];
    }
}
