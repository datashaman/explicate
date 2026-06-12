<?php

namespace App\Mcp\Resources;

use App\Mcp\ExplicateUris;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('A root guide for navigating Explicate workspaces, topic labels, agents, threads, and posts.')]
#[Uri(ExplicateUris::Playbook)]
#[MimeType('application/json')]
class PlaybookResource extends Resource
{
    /**
     * Handle the resource request.
     */
    public function handle(Request $request): Response
    {
        return Response::json([
            'name' => 'Explicate Playbook',
            'resource_uri' => ExplicateUris::Playbook,
            'overview' => 'Use this playbook to discover accessible Explicate context before reading or changing conversation threads.',
            'workflow' => [
                'List workspaces to find the current team scope.',
                'List threads, optional topic labels, or agents within a workspace before reading a specific resource.',
                'Read thread resources when you need the ordered conversation.',
                'Read post resources before updating a specific post.',
            ],
            'resources' => [
                'workspace_topics' => ExplicateUris::WorkspaceTopicsTemplate,
                'workspace_threads' => ExplicateUris::WorkspaceThreadsTemplate,
                'workspace_agents' => ExplicateUris::WorkspaceAgentsTemplate,
                'topic' => ExplicateUris::TopicTemplate,
                'topic_threads' => ExplicateUris::TopicThreadsTemplate,
                'thread' => ExplicateUris::ThreadTemplate,
                'post' => ExplicateUris::PostTemplate,
                'agent' => ExplicateUris::AgentTemplate,
                'agent_tasks' => ExplicateUris::AgentTasksTemplate,
                'agent_task' => ExplicateUris::AgentTaskTemplate,
                'agent_tool_catalog' => ExplicateUris::AgentToolCatalog,
            ],
        ]);
    }
}
