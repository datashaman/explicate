<?php

namespace App\Mcp\Resources;

use App\Mcp\ExplicateUris;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('A top-level guide for navigating Explicate workspaces, topics, agents, and posts.')]
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
            'overview' => 'Use this playbook to discover accessible Explicate context before reading or changing topic posts.',
            'workflow' => [
                'List workspaces to find the current team scope.',
                'List topics or agents within a workspace before reading a specific resource.',
                'Read topic resources when you need topic state, attached agents, and posts together.',
                'Read post resources before updating or creating related draft content.',
            ],
            'resources' => [
                'workspace_topics' => ExplicateUris::WorkspaceTopicsTemplate,
                'workspace_agents' => ExplicateUris::WorkspaceAgentsTemplate,
                'topic' => ExplicateUris::TopicTemplate,
                'topic_posts' => ExplicateUris::TopicPostsTemplate,
                'post' => ExplicateUris::PostTemplate,
                'agent' => ExplicateUris::AgentTemplate,
                'agent_tasks' => ExplicateUris::AgentTasksTemplate,
                'agent_task' => ExplicateUris::AgentTaskTemplate,
            ],
        ]);
    }
}
