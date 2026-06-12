<?php

namespace App\Ai\Tools;

use App\Mcp\ExplicateTools;
use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Container\Container;

class ExplicateToolFactory
{
    public function __construct(private readonly Container $container) {}

    /**
     * @return list<McpToolAdapter>
     */
    public function forAgentTask(User $user, Workspace $workspace, ?Agent $agent = null): array
    {
        $allowedTools = $agent?->latestVersion?->allowed_tools;

        return collect(ExplicateTools::AgentTools)
            ->filter(function (string $tool) use ($allowedTools): bool {
                if ($allowedTools === null) {
                    return true;
                }

                return in_array($this->container->make($tool)->name(), $allowedTools, true);
            })
            ->map(fn (string $tool): McpToolAdapter => new McpToolAdapter(
                $this->container->make($tool),
                $user,
                $workspace,
            ))
            ->values()
            ->all();
    }
}
