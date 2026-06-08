<?php

namespace App\Mcp\Tools;

use App\Mcp\ExplicateContext;
use App\Models\User;
use App\Services\GitRepositoryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('run-git-command')]
#[Description('Run a shell command inside a connected git repository. The command executes in the repository root directory with authentication environment variables injected. Returns stdout, stderr, and the exit code.')]
class RunGitCommandTool extends Tool
{
    public function __construct(protected ExplicateContext $context) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'repo' => ['required', 'string'],
            'command' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $workspace = $this->context->workspaceFor($user);

        $repoName = $validated['repo'];
        $repo = $workspace->repositories
            ->first(fn ($r) => $r->name === $repoName || (string) $r->id === $repoName);

        if (! $repo) {
            return Response::error("Repository '{$repoName}' not found in workspace.");
        }

        if (! $repo->isCloned()) {
            return Response::error("Repository '{$repoName}' has not been cloned yet. It will be synced before the next agent task.");
        }

        $service = new GitRepositoryService($repo);
        $result = $service->run(['sh', '-c', $validated['command']]);

        return Response::structured([
            'repo' => $repo->name,
            'command' => $validated['command'],
            'stdout' => $result['stdout'],
            'stderr' => $result['stderr'],
            'exit_code' => $result['exit_code'],
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'repo' => $schema->string()
                ->description('Repository name or ID.')
                ->required(),
            'command' => $schema->string()
                ->description('Shell command to execute in the repository root directory.')
                ->required(),
        ];
    }
}
