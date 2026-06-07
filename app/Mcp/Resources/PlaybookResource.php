<?php

namespace App\Mcp\Resources;

use App\Mcp\TopicForgeUris;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('A top-level guide for navigating Topic Forge workspaces, topics, agents, and posts.')]
#[Uri(TopicForgeUris::Playbook)]
#[MimeType('application/json')]
class PlaybookResource extends Resource
{
    /**
     * Handle the resource request.
     */
    public function handle(Request $request): Response
    {
        return Response::json([
            'name' => 'Topic Forge Playbook',
            'resource_uri' => TopicForgeUris::Playbook,
            'overview' => 'Use this playbook to discover accessible Topic Forge context before reading or changing topic posts.',
            'workflow' => [
                'List workspaces to find the current team scope.',
                'List topics or agents within a workspace before reading a specific resource.',
                'Read topic resources when you need topic state, attached agents, and posts together.',
                'Read post resources before updating or creating related draft content.',
            ],
            'resources' => [
                'workspace_topics' => TopicForgeUris::WorkspaceTopicsTemplate,
                'workspace_agents' => TopicForgeUris::WorkspaceAgentsTemplate,
                'topic' => TopicForgeUris::TopicTemplate,
                'topic_posts' => TopicForgeUris::TopicPostsTemplate,
                'post' => TopicForgeUris::PostTemplate,
                'agent' => TopicForgeUris::AgentTemplate,
                'agent_tasks' => TopicForgeUris::AgentTasksTemplate,
                'agent_task' => TopicForgeUris::AgentTaskTemplate,
            ],
        ]);
    }
}
