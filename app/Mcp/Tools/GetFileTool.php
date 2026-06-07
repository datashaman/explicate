<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\FormatsMcpPayloads;
use App\Mcp\TopicForgeContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-file')]
#[Description('Read one managed filesystem entry from the current workspace by path.')]
#[IsReadOnly]
#[IsIdempotent]
class GetFileTool extends Tool
{
    use FormatsMcpPayloads;

    public function __construct(protected TopicForgeContext $context) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'path' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $workspace = $this->context->workspaceFor($user);
        $fs = $workspace->filesystem();
        $path = $validated['path'];

        if (! $fs->exists($path)) {
            throw new AuthorizationException('The requested workspace file is not accessible for the authenticated user.');
        }

        $isDirectory = $fs->isDirectory($path);
        $entry = [
            'name' => basename($path),
            'path' => $path,
            'type' => $isDirectory ? 'folder' : 'file',
            'content' => $isDirectory ? null : $fs->read($path),
        ];

        return Response::structured([
            'workspace' => $workspace->only(['id', 'name', 'slug']),
            'file' => $this->workspaceFilePayload($entry, $workspace, includeContent: true),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('Workspace-relative file path.')
                ->required(),
        ];
    }
}
