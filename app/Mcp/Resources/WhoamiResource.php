<?php

namespace App\Mcp\Resources;

use App\Mcp\ExplicateContext;
use App\Mcp\ExplicateUris;
use App\Mcp\Resources\Concerns\HandlesResourceExceptions;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('Show the authenticated Explicate MCP user and current team/workspace context.')]
#[Uri(ExplicateUris::Whoami)]
#[MimeType('application/json')]
class WhoamiResource extends Resource
{
    use HandlesResourceExceptions;

    public function __construct(protected ExplicateContext $context) {}

    /**
     * Handle the resource request.
     */
    public function handle(Request $request): Response
    {
        return $this->guardResource(function () use ($request): Response {
            /** @var User $user */
            $user = $this->context->requireUser($request->user());

            return Response::json([
                'resource_uri' => ExplicateUris::Whoami,
                'authenticated' => true,
                'user' => $user->only(['id', 'name', 'email']),
                'team' => $user->currentTeam?->only(['id', 'name', 'slug']),
                'workspace' => $user->currentWorkspace?->only(['id', 'name', 'slug']),
            ]);
        });
    }
}
