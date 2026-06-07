<?php

namespace App\Mcp\Tools;

use App\Enums\WorkspaceFileType;
use App\Mcp\Concerns\FormatsMcpPayloads;
use App\Mcp\TopicForgeContext;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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

    public function __construct(protected TopicForgeContext $context) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'path' => ['required', 'string'],
            'type' => ['nullable', 'string', Rule::enum(WorkspaceFileType::class)],
            'content' => ['nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $workspace = $this->context->workspaceFor($user);
        $path = WorkspaceFile::normalizePath($validated['path']);
        $type = WorkspaceFileType::from($validated['type'] ?? WorkspaceFileType::File->value);

        $file = DB::transaction(function () use ($workspace, $path, $type, $validated): WorkspaceFile {
            $parent = $this->ensureParentFolders($workspace, $path);
            $name = basename($path);

            $file = $workspace->files()->where('path', $path)->first();

            if (! $file instanceof WorkspaceFile) {
                $file = $workspace->files()->make();
            }

            $file->fill([
                'parent_id' => $parent?->id,
                'type' => $type,
                'name' => $name,
                'content' => $type === WorkspaceFileType::File ? ($validated['content'] ?? $file->content ?? '') : null,
            ]);
            $file->save();

            return $file;
        });

        return Response::structured([
            'workspace' => $workspace->only(['id', 'name', 'slug']),
            'file' => $this->workspaceFilePayload($file, includeContent: true),
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
                ->enum(WorkspaceFileType::class)
                ->nullable(),
            'content' => $schema->string()
                ->description('File content. Ignored for folders.')
                ->nullable(),
        ];
    }

    private function ensureParentFolders(Workspace $workspace, string $path): ?WorkspaceFile
    {
        $segments = explode('/', $path);
        array_pop($segments);

        $parent = null;

        foreach ($segments as $segment) {
            $folderPath = WorkspaceFile::buildPath($parent, $segment);
            $folder = $workspace->files()->where('path', $folderPath)->first();

            if (! $folder instanceof WorkspaceFile) {
                $folder = $workspace->files()->create([
                    'parent_id' => $parent?->id,
                    'type' => WorkspaceFileType::Folder,
                    'name' => $segment,
                ]);
            }

            $parent = $folder;
        }

        return $parent;
    }
}
