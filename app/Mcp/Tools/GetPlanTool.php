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

#[Name('get-plan')]
#[Description('Get the implementation plan for a brief in the current workspace.')]
#[IsReadOnly]
#[IsIdempotent]
class GetPlanTool extends Tool
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
        $plan = $this->context->planFor($user, (int) $validated['brief_id']);
        $plan->load(['brief.workspace', 'brief.sourceThread.workspace', 'tasks']);

        return Response::structured([
            'workspace' => $plan->brief->workspace->only(['id', 'name', 'slug']),
            'plan' => $this->planPayload($plan),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'brief_id' => $schema->integer()
                ->description('The brief id whose plan should be fetched.')
                ->required(),
        ];
    }
}
