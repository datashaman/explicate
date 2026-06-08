<?php

namespace App\Mcp\Resources;

use App\Mcp\Concerns\FormatsMcpPayloads;
use App\Mcp\ExplicateContext;
use App\Mcp\ExplicateUris;
use App\Mcp\Resources\Concerns\HandlesResourceExceptions;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Description('Read one post-derived task queued for an agent inside an accessible workspace.')]
class AgentTaskResource extends Resource implements HasUriTemplate
{
    use FormatsMcpPayloads;
    use HandlesResourceExceptions;

    public function __construct(protected ExplicateContext $context) {}

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate(ExplicateUris::AgentTaskTemplate);
    }

    public function handle(Request $request): Response
    {
        return $this->guardResource(function () use ($request): Response {
            /** @var User $user */
            $user = $this->context->requireUser($request->user());
            $task = $this->context->agentTaskFor(
                $user,
                (string) $request->get('agent'),
                (int) $request->get('task'),
                (string) $request->get('workspace'),
            );
            $task->load(['agent.workspace', 'post.topic.workspace', 'post.sender.user', 'post.sender.agent']);

            return Response::json([
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
        });
    }
}
