<?php

namespace App\Mcp\Tools;

use App\Actions\Threads\StartConversation;
use App\Enums\PostStatus;
use App\Mcp\Concerns\FormatsMcpPayloads;
use App\Mcp\ExplicateContext;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create-thread')]
#[Description('Start a new thread in the current workspace. Topics are optional labels.')]
class CreateThreadTool extends Tool
{
    use FormatsMcpPayloads;

    public function __construct(protected ExplicateContext $context) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'body' => ['required', 'string'],
            'topic_slug' => ['nullable', 'string'],
            'title' => ['nullable', 'string'],
            'status' => ['nullable', 'string', Rule::enum(PostStatus::class)],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $workspace = $this->context->workspaceFor($user);
        $topic = filled($validated['topic_slug'] ?? null)
            ? $this->context->topicFor($user, $validated['topic_slug'])
            : null;

        $post = app(StartConversation::class)->handle(
            workspace: $workspace,
            sender: $workspace->principalForUser($user),
            body: $validated['body'],
            status: PostStatus::from($validated['status'] ?? PostStatus::Published->value),
            topic: $topic,
            title: $validated['title'] ?? null,
        );
        $post->loadMissing('thread');

        return Response::structured([
            'workspace' => $workspace->only(['id', 'name', 'slug']),
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
            'body' => $schema->string()
                ->description('The first post body.')
                ->required(),
            'topic_slug' => $schema->string()
                ->description('Optional topic label slug.')
                ->nullable(),
            'title' => $schema->string()
                ->description('Optional thread title. Defaults from the body.')
                ->nullable(),
            'status' => $schema->string()
                ->description('Optional first-post status.')
                ->enum(PostStatus::class)
                ->nullable(),
        ];
    }
}
