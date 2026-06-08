<?php

namespace App\Ai\Tools;

use App\Mcp\ExplicateTools;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Container\Container;

class ExplicateToolFactory
{
    public function __construct(private readonly Container $container) {}

    /**
     * @return list<McpToolAdapter>
     */
    public function forAgentTask(User $user, Workspace $workspace): array
    {
        return collect(ExplicateTools::AgentTools)
            ->map(fn (string $tool): McpToolAdapter => new McpToolAdapter(
                $this->container->make($tool),
                $user,
                $workspace,
            ))
            ->values()
            ->all();
    }
}
