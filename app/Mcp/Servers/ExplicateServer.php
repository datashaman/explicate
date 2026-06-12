<?php

namespace App\Mcp\Servers;

use App\Mcp\ExplicateTools;
use App\Mcp\Resources\AgentResource;
use App\Mcp\Resources\AgentTaskResource;
use App\Mcp\Resources\AgentTasksResource;
use App\Mcp\Resources\BriefResource;
use App\Mcp\Resources\PlanResource;
use App\Mcp\Resources\PlaybookResource;
use App\Mcp\Resources\PostResource;
use App\Mcp\Resources\ThreadResource;
use App\Mcp\Resources\TopicResource;
use App\Mcp\Resources\TopicThreadsResource;
use App\Mcp\Resources\WhoamiResource;
use App\Mcp\Resources\WorkspaceAgentsResource;
use App\Mcp\Resources\WorkspaceBriefsResource;
use App\Mcp\Resources\WorkspacesResource;
use App\Mcp\Resources\WorkspaceThreadsResource;
use App\Mcp\Resources\WorkspaceTopicsResource;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Transport\StdioTransport;
use Throwable;

#[Name('Explicate Server')]
#[Version('0.0.1')]
#[Instructions('Use this server to inspect the authenticated user\'s current team, browse workspaces, optional topic labels, agents, threads, and posts, read thread and post state, and create topics, agents, threads, or replies inside accessible workspaces.')]
class ExplicateServer extends Server
{
    public int $maxPaginationLength = 250;

    public int $defaultPaginationLength = 250;

    protected array $tools = [
        ...ExplicateTools::Tools,
    ];

    protected array $resources = [
        WhoamiResource::class,
        WorkspacesResource::class,
        PlaybookResource::class,
        WorkspaceTopicsResource::class,
        WorkspaceThreadsResource::class,
        WorkspaceBriefsResource::class,
        WorkspaceAgentsResource::class,
        AgentTasksResource::class,
        AgentTaskResource::class,
        TopicThreadsResource::class,
        TopicResource::class,
        ThreadResource::class,
        PostResource::class,
        BriefResource::class,
        PlanResource::class,
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
