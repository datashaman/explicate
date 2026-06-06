<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\FormatsMcpPayloads;
use App\Mcp\TopicForgeContext;
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

#[Name('list-agent-tasks')]
#[Description('List message-derived work queued for an agent inside the current workspace.')]
#[IsReadOnly]
#[IsIdempotent]
class ListAgentTasksTool extends Tool
{
    use FormatsMcpPayloads;

    public function __construct(protected TopicForgeContext $context) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'agent_slug' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $agent = $this->context->agentFor($user, $validated['agent_slug']);

        $tasks = $agent->tasks()
            ->with(['agent.workspace', 'message.topic.workspace', 'message.sender.user', 'message.sender.agent', 'message.recipient.user', 'message.recipient.agent'])
            ->orderByDesc('priority')
            ->orderBy('available_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($task) => $this->agentTaskPayload($task))
            ->values()
            ->all();

        return Response::structured([
            'workspace' => $agent->workspace->only(['id', 'name', 'slug']),
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'slug' => $agent->slug,
                'resource_uri' => "topic-forge://workspaces/{$agent->workspace->slug}/agents/{$agent->slug}",
                'tasks_resource_uri' => "topic-forge://workspaces/{$agent->workspace->slug}/agents/{$agent->slug}/tasks",
            ],
            'tasks' => $tasks,
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
                ->description('The agent slug whose queued work should be listed.')
                ->required(),
        ];
    }
}
