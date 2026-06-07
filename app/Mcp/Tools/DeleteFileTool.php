<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\FormatsMcpPayloads;
use App\Mcp\TopicForgeContext;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete-file')]
#[Description('Delete a managed workspace file or folder by path. Deleting a folder deletes everything inside it.')]
#[IsDestructive]
class DeleteFileTool extends Tool
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
        $file = $this->context->workspaceFileFor($user, $validated['path']);
        $payload = $this->workspaceFilePayload($file);

        $file->delete();

        return Response::structured([
            'workspace' => $file->workspace->only(['id', 'name', 'slug']),
            'file' => $payload,
            'deleted' => true,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('Workspace-relative file or folder path.')
                ->required(),
        ];
    }
}
