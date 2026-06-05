<?php

namespace App\Mcp\Servers;

use App\Mcp\LocalMcpUserAuthenticator;
use App\Mcp\Resources\TopicResource;
use App\Mcp\Tools\CreateMessageTool;
use App\Mcp\Tools\GetAgentTool;
use App\Mcp\Tools\GetMessageTool;
use App\Mcp\Tools\GetTopicTool;
use App\Mcp\Tools\ListAgentsTool;
use App\Mcp\Tools\ListMessagesTool;
use App\Mcp\Tools\ListTopicsTool;
use App\Mcp\Tools\ListWorkspacesTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Topic Forge Server')]
#[Version('0.0.1')]
#[Instructions('Use this server to inspect the authenticated user\'s current team, browse workspaces, topics, agents, and messages, read topic and message state, and create draft or published messages inside accessible topics.')]
class TopicForgeServer extends Server
{
    protected array $tools = [
        ListWorkspacesTool::class,
        ListTopicsTool::class,
        ListAgentsTool::class,
        GetTopicTool::class,
        GetAgentTool::class,
        ListMessagesTool::class,
        GetMessageTool::class,
        CreateMessageTool::class,
    ];

    protected array $resources = [
        TopicResource::class,
    ];

    protected array $prompts = [
        //
    ];

    protected function boot(): void
    {
        if (app()->runningInConsole()) {
            app(LocalMcpUserAuthenticator::class)->authenticate();
        }
    }
}
