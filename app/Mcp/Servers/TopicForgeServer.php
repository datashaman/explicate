<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\AgentResource;
use App\Mcp\Resources\AgentTaskResource;
use App\Mcp\Resources\AgentTasksResource;
use App\Mcp\Resources\PlaybookResource;
use App\Mcp\Resources\PostResource;
use App\Mcp\Resources\TopicPostsResource;
use App\Mcp\Resources\TopicResource;
use App\Mcp\Resources\WhoamiResource;
use App\Mcp\Resources\WorkspaceAgentsResource;
use App\Mcp\Resources\WorkspacesResource;
use App\Mcp\Resources\WorkspaceTopicsResource;
use App\Mcp\Tools\CreateAgentTool;
use App\Mcp\Tools\CreatePostTool;
use App\Mcp\Tools\GetAgentTaskTool;
use App\Mcp\Tools\GetAgentTool;
use App\Mcp\Tools\GetPostTool;
use App\Mcp\Tools\GetTopicTool;
use App\Mcp\Tools\ListAgentsTool;
use App\Mcp\Tools\ListAgentTasksTool;
use App\Mcp\Tools\ListPostsTool;
use App\Mcp\Tools\ListTopicsTool;
use App\Mcp\Tools\ListWorkspacesTool;
use App\Mcp\Tools\SwitchWorkspaceTool;
use App\Mcp\Tools\UpdateAgentTool;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Transport\StdioTransport;
use Throwable;

#[Name('Topic Forge Server')]
#[Version('0.0.1')]
#[Instructions('Use this server to inspect the authenticated user\'s current team, browse workspaces, topics, agents, and posts, read topic and post state, and create draft or published posts inside accessible topics.')]
class TopicForgeServer extends Server
{
    protected array $tools = [
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
        CreateAgentTool::class,
        UpdateAgentTool::class,
        CreatePostTool::class,
    ];

    protected array $resources = [
        WhoamiResource::class,
        WorkspacesResource::class,
        PlaybookResource::class,
        WorkspaceTopicsResource::class,
        WorkspaceAgentsResource::class,
        AgentTasksResource::class,
        AgentTaskResource::class,
        TopicPostsResource::class,
        TopicResource::class,
        PostResource::class,
        AgentResource::class,
    ];

    protected array $prompts = [
        //
    ];

    protected function boot(): void
    {
        if (! $this->transport instanceof StdioTransport) {
            return;
        }

        try {
            $this->authenticateLocalSession(
                (string) config('mcp.local_auth_user', ''),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function authenticateLocalSession(
        string $userEmail = '',
    ): ?int {
        if ($userEmail !== '') {
            return $this->authenticateLocalUser(User::where('email', $userEmail)->first(), "MCP_LOCAL_AUTH_USER [{$userEmail}]");
        }

        return null;
    }

    private function authenticateLocalUser(?User $user, string $source): ?int
    {
        if ($user === null) {
            fwrite(STDERR, "[mcp-server] {$source} does not match any user; running unauthenticated.".PHP_EOL);

            return null;
        }

        Auth::login($user);

        return $user->id;
    }
}
