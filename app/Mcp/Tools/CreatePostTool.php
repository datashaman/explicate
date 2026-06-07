<?php

namespace App\Mcp\Tools;

use App\Actions\Posts\CreatePost;
use App\Enums\PostStatus;
use App\Mcp\TopicForgeContext;
use App\Mcp\TopicForgeUris;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create-post')]
#[Description('Create a post inside a topic in the current workspace.')]
class CreatePostTool extends Tool
{
    public function __construct(protected TopicForgeContext $context) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'topic_slug' => ['required', 'string'],
            'body' => ['required', 'string'],
            'status' => ['nullable', 'string', Rule::enum(PostStatus::class)],
            'agent_ids' => ['nullable', 'array'],
            'agent_ids.*' => ['integer'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $topic = $this->context->topicFor($user, $validated['topic_slug']);
        $post = app(CreatePost::class)->handle(
            topic: $topic,
            sender: $topic->workspace->principalForUser($user),
            body: $validated['body'],
            status: PostStatus::from($validated['status'] ?? PostStatus::Draft->value),
            agentIds: $validated['agent_ids'] ?? [],
        );

        return Response::structured([
            'workspace' => $topic->workspace->only(['id', 'name', 'slug']),
            'topic' => [
                ...$topic->only(['id', 'name', 'slug']),
                'resource_uri' => TopicForgeUris::topic($topic),
            ],
            'post' => [
                'id' => $post->id,
                'ulid' => $post->ulid,
                'preview' => $post->preview(),
                'status' => $post->status->value,
                'sender_principal_id' => $post->sender_principal_id,
                'assigned_agent_ids' => $post->assignedAgentIds(),
                'resource_uri' => TopicForgeUris::post($post),
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
                ->description('The topic slug the post should be created in.')
                ->required(),
            'body' => $schema->string()
                ->description('The post body.')
                ->required(),
            'status' => $schema->string()
                ->description('Optional post status.')
                ->enum(PostStatus::class)
                ->nullable(),
            'agent_ids' => $schema->array()
                ->description('Optional workspace agent ids to assign to this post, creating agent tasks.')
                ->nullable(),
        ];
    }
}
