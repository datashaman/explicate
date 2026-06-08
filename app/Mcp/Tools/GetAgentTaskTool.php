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

#[Name('get-agent-task')]
#[Description('Get one post-derived task queued for an agent inside the current workspace.')]
#[IsReadOnly]
#[IsIdempotent]
class GetAgentTaskTool extends Tool
{
    use FormatsMcpPayloads;

    public function __construct(protected ExplicateContext $context) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'agent_slug' => ['required', 'string'],
            'task_id' => ['required', 'integer'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $task = $this->context->agentTaskFor(
            $user,
            $validated['agent_slug'],
            (int) $validated['task_id'],
        );
        $task->load(['agent.workspace', 'post.topic.workspace', 'post.sender.user', 'post.sender.agent']);

        return Response::structured([
            'workspace' => $task->agent->workspace->only(['id', 'name', 'slug']),
            'agent' => [
                'id' => $task->agent->id,
                'name' => $task->agent->name,
                'slug' => $task->agent->slug,
                'resource_uri' => ExplicateUris::agent($task->agent),
                'tasks_resource_uri' => ExplicateUris::agentTasks($task->agent),
            ],
            'task' => $this->agentTaskWithPostPayload($task),
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
            'agent_slug' => $schema->string()
                ->description('The agent slug that owns the task.')
                ->required(),
            'task_id' => $schema->integer()
                ->description('The agent task id to fetch.')
                ->required(),
        ];
    }
}
