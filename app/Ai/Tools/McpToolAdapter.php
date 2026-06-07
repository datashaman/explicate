<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Contracts\Tool as AiTool;
use Laravel\Ai\Tools\Request as AiRequest;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool as McpTool;
use Stringable;

class McpToolAdapter implements AiTool
{
    public function __construct(
        private readonly McpTool $tool,
        private readonly User $user,
        private readonly Workspace $workspace,
    ) {}

    public function name(): string
    {
        return $this->tool->name();
    }

    public function description(): Stringable|string
    {
        return $this->tool->description();
    }

    public function handle(AiRequest $request): Stringable|string
    {
        $previousUser = Auth::user();
        $toolUser = $this->user->fresh(['currentTeam']) ?? $this->user;
        $toolUser->forceFill(['current_workspace_id' => $this->workspace->id]);
        $toolUser->setRelation('currentWorkspace', $this->workspace);

        Auth::guard()->setUser($toolUser);

        try {
            return $this->stringifyResponse(
                $this->tool->handle(new McpRequest($request->all()))
            );
        } finally {
            if ($previousUser instanceof Authenticatable) {
                Auth::guard()->setUser($previousUser);
            } else {
                Auth::guard()->forgetUser();
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->tool->schema($schema);
    }

    private function stringifyResponse(Response|ResponseFactory $response): string
    {
        if ($response instanceof ResponseFactory) {
            if ($structuredContent = $response->getStructuredContent()) {
                return json_encode($structuredContent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            return $response->responses()
                ->map(fn (Response $response): string => (string) $response->content())
                ->implode("\n");
        }

        return (string) $response->content();
    }
}
