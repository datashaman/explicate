<?php

namespace App\Mcp\Resources;

use App\Mcp\Concerns\FormatsMcpPayloads;
use App\Mcp\Resources\Concerns\HandlesResourceExceptions;
use App\Mcp\TopicForgeContext;
use App\Mcp\TopicForgeUris;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Description('List post-derived work queued for an agent inside an accessible workspace.')]
class AgentTasksResource extends Resource implements HasUriTemplate
{
    use FormatsMcpPayloads;
    use HandlesResourceExceptions;

    public function __construct(protected TopicForgeContext $context) {}

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate(TopicForgeUris::AgentTasksTemplate);
    }

    public function handle(Request $request): Response
    {
        return $this->guardResource(function () use ($request): Response {
            /** @var User $user */
            $user = $this->context->requireUser($request->user());
            $agent = $this->context->agentFor(
                $user,
                (string) $request->get('agent'),
                (string) $request->get('workspace'),
            );

            $tasks = $agent->tasks()
                ->with(['agent.workspace', 'post.topic.workspace', 'post.sender.user', 'post.sender.agent'])
                ->orderByDesc('priority')
                ->orderBy('available_at')
                ->orderBy('id')
                ->get()
                ->map(fn ($task) => $this->agentTaskPayload($task))
                ->values()
                ->all();

            return Response::json([
                'workspace' => $agent->workspace->only(['id', 'name', 'slug']),
                'agent' => [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'slug' => $agent->slug,
                    'resource_uri' => TopicForgeUris::agent($agent),
                    'tasks_resource_uri' => TopicForgeUris::agentTasks($agent),
                ],
                'tasks' => $tasks,
            ]);
        });
    }
}
