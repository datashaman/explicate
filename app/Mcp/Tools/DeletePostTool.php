<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\FormatsMcpPayloads;
use App\Mcp\ExplicateContext;
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
#[Description('Delete one of the authenticated user\'s posts inside the current workspace.')]
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
            'post_ulid' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $post = $this->context->postFor($user, $validated['post_ulid']);
        $post->loadMissing('thread.workspace');
        $sender = $post->thread->workspace->principalForUser($user);

        if ($post->sender_principal_id !== $sender->id) {
            throw new AuthorizationException('Only the post sender can delete this post.');
        }

        $post->load(['thread.workspace', 'thread.topic', 'sender.user', 'sender.agent']);
        $payload = $this->postSummaryPayload($post);
        $threadPayload = $this->threadSummaryPayload($post->thread);

        DB::transaction(function () use ($post): void {
            $post->attachments()->delete();
            $post->agentTasks()->get()->each->delete();
            $post->delete();
        });

        return Response::structured([
            'workspace' => $post->thread->workspace->only(['id', 'name', 'slug']),
            'thread' => $threadPayload,
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
            'post_ulid' => $schema->string()
                ->description('The post ULID to delete.')
                ->required(),
        ];
    }
}
