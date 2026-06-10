<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\FormatsMcpPayloads;
use App\Mcp\ExplicateContext;
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

#[Name('get-thread')]
#[Description('Get a thread and its ordered conversation posts.')]
#[IsReadOnly]
#[IsIdempotent]
class GetThreadTool extends Tool
{
    use FormatsMcpPayloads;

    public function __construct(protected ExplicateContext $context) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'thread' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $thread = $this->context->threadFor($user, $validated['thread']);
        $thread->load(['workspace', 'topic', 'latestPost.sender.user', 'latestPost.sender.agent']);

        return Response::structured([
            'workspace' => $thread->workspace->only(['id', 'name', 'slug']),
            'thread' => $this->threadSummaryPayload($thread),
            'posts' => $thread->conversationPosts()
                ->map(fn ($post) => $this->postPayload($post))
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'thread' => $schema->string()
                ->description('The thread slug or id.')
                ->required(),
        ];
    }
}
