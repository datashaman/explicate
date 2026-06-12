<?php

namespace App\Mcp\Tools;

use App\Mcp\ExplicateContext;
use App\Models\User;
use App\Services\AiProviderKeyService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-provider-keys')]
#[Description('List AI providers with configured keys for the authenticated user\'s current workspace. Secret key values are never returned.')]
#[IsReadOnly]
#[IsIdempotent]
class ListProviderKeysTool extends Tool
{
    public function __construct(
        protected ExplicateContext $context,
        private readonly AiProviderKeyService $providerKeys,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $workspace = $this->context->workspaceFor($user);

        return Response::structured([
            'workspace' => $workspace->only(['id', 'name', 'slug']),
            'providers' => $this->providerKeys->availableProvidersForWorkspace($workspace),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
