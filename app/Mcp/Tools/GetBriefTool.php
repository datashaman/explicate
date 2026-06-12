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

#[Name('get-brief')]
#[Description('Get one brief from the current workspace.')]
#[IsReadOnly]
#[IsIdempotent]
class GetBriefTool extends Tool
{
    use FormatsMcpPayloads;

    public function __construct(protected ExplicateContext $context) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'brief_id' => ['required', 'integer'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $brief = $this->context->briefFor($user, (int) $validated['brief_id']);
        $brief->load(['workspace', 'sourceThread.workspace', 'plan.tasks']);

        return Response::structured([
            'workspace' => $brief->workspace->only(['id', 'name', 'slug']),
            'brief' => $this->briefPayload($brief),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'brief_id' => $schema->integer()
                ->description('The brief id to fetch.')
                ->required(),
        ];
    }
}
