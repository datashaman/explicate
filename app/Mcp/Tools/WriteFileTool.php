<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\FormatsMcpPayloads;
use App\Mcp\ExplicateContext;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('write-file')]
#[Description('Create or update a managed file or folder in the current workspace. Parent folders are created automatically.')]
class WriteFileTool extends Tool
{
    use FormatsMcpPayloads;

    public function __construct(protected ExplicateContext $context) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'path' => ['required', 'string'],
            'type' => ['nullable', 'string', 'in:file,folder'],
            'content' => ['nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $workspace = $this->context->workspaceFor($user);
        $fs = $workspace->filesystem();
        $path = $validated['path'];
        $type = $validated['type'] ?? 'file';

        if ($type === 'folder') {
            $fs->mkdir($path);
            $content = null;
        } else {
            $existingContent = ($fs->exists($path) && ! $fs->isDirectory($path)) ? $fs->read($path) : '';
            $content = $validated['content'] ?? $existingContent;
            $fs->write($path, $content);
        }

        $entry = [
            'name' => basename($path),
            'path' => $path,
            'type' => $type,
            'content' => $content,
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
                ->description('Workspace-relative path to create or update.')
                ->required(),
            'type' => $schema->string()
                ->description('Entry type. Defaults to file.')
                ->enum(['file', 'folder'])
                ->nullable(),
            'content' => $schema->string()
                ->description('File content. Ignored for folders.')
                ->nullable(),
        ];
    }
}
