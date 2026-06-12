<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\FormatsMcpPayloads;
use App\Mcp\ExplicateContext;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete-brief')]
#[Description('Delete a brief in the current workspace, including its plan and tasks if present.')]
#[IsDestructive]
class DeleteBriefTool extends Tool
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

        $payload = $this->briefPayload($brief);
        $workspacePayload = $brief->workspace->only(['id', 'name', 'slug']);

        DB::transaction(function () use ($brief): void {
            if ($brief->plan) {
                $brief->plan->tasks()->delete();
                $brief->plan()->delete();
            }

            $brief->delete();
        });

        return Response::structured([
            'workspace' => $workspacePayload,
            'brief' => $payload,
            'deleted' => true,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'brief_id' => $schema->integer()
                ->description('The brief id to delete.')
                ->required(),
        ];
    }
}
