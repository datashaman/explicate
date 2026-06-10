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

#[Name('list-threads')]
#[Description('List threads for the current workspace, optionally filtered by topic label.')]
#[IsReadOnly]
#[IsIdempotent]
class ListThreadsTool extends Tool
{
    use FormatsMcpPayloads;

    public function __construct(protected ExplicateContext $context) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'topic_slug' => ['nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $workspace = $this->context->workspaceFor($user);
        $topic = filled($validated['topic_slug'] ?? null)
            ? $this->context->topicFor($user, $validated['topic_slug'])
            : null;

        $threads = $workspace->threads()
            ->when($topic, fn ($query) => $query->whereBelongsTo($topic))
            ->whereHas('posts')
            ->with(['workspace', 'topic', 'latestPost.sender.user', 'latestPost.sender.agent'])
            ->withCount('posts')
            ->get()
            ->map(fn ($thread) => $this->threadSummaryPayload($thread))
            ->values()
            ->all();

        return Response::structured([
            'workspace' => $workspace->only(['id', 'name', 'slug']),
            'topic' => $topic ? [
                ...$topic->only(['id', 'name', 'slug']),
                'resource_uri' => ExplicateUris::topic($topic),
            ] : null,
            'threads' => $threads,
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
                ->description('Optional topic slug to filter threads.')
                ->nullable(),
        ];
    }
}
