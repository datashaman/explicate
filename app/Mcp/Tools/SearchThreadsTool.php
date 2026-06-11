<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\FormatsMcpPayloads;
use App\Mcp\ExplicateContext;
use App\Mcp\ExplicateUris;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search-threads')]
#[Description('Search threads in the current workspace by thread title, summary, or post body.')]
#[IsReadOnly]
#[IsIdempotent]
class SearchThreadsTool extends Tool
{
    use FormatsMcpPayloads;

    public function __construct(protected ExplicateContext $context) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:1', 'max:255'],
            'topic_slug' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $workspace = $this->context->workspaceFor($user);
        $topic = filled($validated['topic_slug'] ?? null)
            ? $this->context->topicFor($user, $validated['topic_slug'])
            : null;
        $search = addcslashes($validated['query'], '\%_');
        $limit = (int) ($validated['limit'] ?? 10);

        $threads = $workspace->threads()
            ->when($topic, fn (Builder $query) => $query->whereBelongsTo($topic))
            ->where(function (Builder $query) use ($search): void {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('summary', 'like', "%{$search}%")
                    ->orWhereHas('posts', fn (Builder $postQuery) => $postQuery->where('body', 'like', "%{$search}%"));
            })
            ->whereHas('posts')
            ->with(['workspace', 'topic', 'latestPost.sender.user', 'latestPost.sender.agent'])
            ->withCount('posts')
            ->limit($limit)
            ->get()
            ->map(fn ($thread) => $this->threadSummaryPayload($thread))
            ->values()
            ->all();

        return Response::structured([
            'workspace' => $workspace->only(['id', 'name', 'slug']),
            'query' => $validated['query'],
            'topic' => $topic ? [
                ...$topic->only(['id', 'name', 'slug']),
                'resource_uri' => ExplicateUris::topic($topic),
            ] : null,
            'threads' => $threads,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Search text to match against thread titles, summaries, and post bodies.')
                ->required(),
            'topic_slug' => $schema->string()
                ->description('Optional topic slug to filter threads.')
                ->nullable(),
            'limit' => $schema->integer()
                ->description('Maximum number of matching threads to return. Defaults to 10, maximum 50.')
                ->nullable(),
        ];
    }
}
