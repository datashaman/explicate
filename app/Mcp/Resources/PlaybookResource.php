<?php

namespace App\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('A top-level guide for navigating Topic Forge workspaces, topics, agents, and messages.')]
#[Uri('topic-forge://playbook')]
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
            'resource_uri' => 'topic-forge://playbook',
            'overview' => 'Use this playbook to discover accessible Topic Forge context before reading or changing topic messages.',
            'workflow' => [
                'List workspaces to find the current team scope.',
                'List topics or agents within a workspace before reading a specific resource.',
                'Read topic resources when you need topic state, attached agents, and messages together.',
                'Read message resources before updating or creating related draft content.',
            ],
            'resources' => [
                'workspace_topics' => 'topic-forge://workspaces/{workspace}/topics',
                'workspace_agents' => 'topic-forge://workspaces/{workspace}/agents',
                'topic' => 'topic-forge://workspaces/{workspace}/topics/{topic}',
                'topic_messages' => 'topic-forge://workspaces/{workspace}/topics/{topic}/messages',
                'message' => 'topic-forge://workspaces/{workspace}/topics/{topic}/messages/{message}',
                'agent' => 'topic-forge://workspaces/{workspace}/agents/{agent}',
                'agent_tasks' => 'topic-forge://workspaces/{workspace}/agents/{agent}/tasks',
                'agent_task' => 'topic-forge://workspaces/{workspace}/agents/{agent}/tasks/{task}',
            ],
        ]);
    }
}
