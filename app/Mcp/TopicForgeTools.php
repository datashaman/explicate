<?php

namespace App\Mcp;

use App\Mcp\Tools\CreateAgentTool;
use App\Mcp\Tools\CreatePostTool;
use App\Mcp\Tools\CreateTopicTool;
use App\Mcp\Tools\DeleteFileTool;
use App\Mcp\Tools\DeletePostTool;
use App\Mcp\Tools\GetAgentTaskTool;
use App\Mcp\Tools\GetAgentTool;
use App\Mcp\Tools\GetFileTool;
use App\Mcp\Tools\GetPostTool;
use App\Mcp\Tools\GetTopicTool;
use App\Mcp\Tools\ListAgentsTool;
use App\Mcp\Tools\ListAgentTasksTool;
use App\Mcp\Tools\ListFilesTool;
use App\Mcp\Tools\ListPostsTool;
use App\Mcp\Tools\ListReposTool;
use App\Mcp\Tools\ListTopicsTool;
use App\Mcp\Tools\ListWorkspacesTool;
use App\Mcp\Tools\RunGitCommandTool;
use App\Mcp\Tools\SwitchWorkspaceTool;
use App\Mcp\Tools\UpdateAgentTool;
use App\Mcp\Tools\UpdatePostTool;
use App\Mcp\Tools\WhoAmITool;
use App\Mcp\Tools\WriteFileTool;
use Laravel\Mcp\Server\Tool;

final class TopicForgeTools
{
    /** @var list<class-string<Tool>> */
    public const array Tools = [
        WhoAmITool::class,
        ListWorkspacesTool::class,
        SwitchWorkspaceTool::class,
        ListTopicsTool::class,
        ListAgentsTool::class,
        ListAgentTasksTool::class,
        GetAgentTaskTool::class,
        GetTopicTool::class,
        GetAgentTool::class,
        ListPostsTool::class,
        GetPostTool::class,
        CreateTopicTool::class,
        CreateAgentTool::class,
        UpdateAgentTool::class,
        CreatePostTool::class,
        UpdatePostTool::class,
        DeletePostTool::class,
        ListFilesTool::class,
        GetFileTool::class,
        WriteFileTool::class,
        DeleteFileTool::class,
        ListReposTool::class,
        RunGitCommandTool::class,
    ];

    /** @var list<class-string<Tool>> */
    public const array AgentTools = [
        WhoAmITool::class,
        ListWorkspacesTool::class,
        ListTopicsTool::class,
        ListAgentsTool::class,
        ListAgentTasksTool::class,
        GetAgentTaskTool::class,
        GetTopicTool::class,
        GetAgentTool::class,
        ListPostsTool::class,
        GetPostTool::class,
        CreateTopicTool::class,
        CreatePostTool::class,
        UpdatePostTool::class,
        DeletePostTool::class,
        ListFilesTool::class,
        GetFileTool::class,
        WriteFileTool::class,
        DeleteFileTool::class,
        ListReposTool::class,
        RunGitCommandTool::class,
    ];
}
