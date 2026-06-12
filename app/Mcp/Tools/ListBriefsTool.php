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

#[Name('list-briefs')]
#[Description('List briefs in the current workspace.')]
#[IsReadOnly]
#[IsIdempotent]
class ListBriefsTool extends Tool
{
    use FormatsMcpPayloads;

    public function __construct(protected ExplicateContext $context) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $workspace = $this->context->workspaceFor($user);

        $briefs = $workspace->briefs()
            ->with(['workspace', 'sourceThread.workspace', 'plan.tasks'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn ($brief) => $this->briefSummaryPayload($brief))
            ->values()
            ->all();

        return Response::structured([
            'workspace' => $workspace->only(['id', 'name', 'slug']),
            'resource_uri' => ExplicateUris::workspaceBriefs($workspace),
            'briefs' => $briefs,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
