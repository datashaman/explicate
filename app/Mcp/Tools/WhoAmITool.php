<?php

namespace App\Mcp\Tools;

use App\Mcp\TopicForgeUris;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('who-am-i')]
#[Description('Show the authenticated Topic Forge MCP user and current team/workspace context.')]
#[IsReadOnly]
#[IsIdempotent]
class WhoAmITool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $request->user();

        if (! $user instanceof User && auth()->user() instanceof User) {
            $user = auth()->user();
        }

        if (! $user instanceof User) {
            return Response::structured([
                'resource_uri' => TopicForgeUris::Whoami,
                'authenticated' => false,
                'user' => null,
                'team' => null,
                'workspace' => null,
            ]);
        }

        return Response::structured([
            'resource_uri' => TopicForgeUris::Whoami,
            'authenticated' => true,
            'user' => $user->only(['id', 'name', 'email']),
            'team' => $user->currentTeam?->only(['id', 'name', 'slug']),
            'workspace' => $user->currentWorkspace?->only(['id', 'name', 'slug']),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
