<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\FormatsMcpPayloads;
use App\Mcp\TopicForgeContext;
use App\Mcp\TopicForgeUris;
use App\Models\User;
use App\Services\WorkspaceFilesystemService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-files')]
#[Description('List the managed filesystem entries for the current workspace.')]
#[IsReadOnly]
#[IsIdempotent]
class ListFilesTool extends Tool
{
    use FormatsMcpPayloads;

    public function __construct(protected TopicForgeContext $context) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $workspace = $this->context->workspaceFor($user);
        $fs = $workspace->filesystem();

        return Response::structured([
            'workspace' => [
                ...$workspace->only(['id', 'name', 'slug']),
                'resource_uri' => TopicForgeUris::workspaceFiles($workspace),
            ],
            'files' => collect($this->collectAllEntries($fs, ''))
                ->map(fn (array $entry): array => $this->workspaceFilePayload($entry, $workspace))
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @return array<int, array{name: string, path: string, type: string}>
     */
    private function collectAllEntries(WorkspaceFilesystemService $fs, string $directory): array
    {
        $entries = $fs->list($directory);
        $all = [];

        foreach ($entries as $entry) {
            $all[] = $entry;
            if ($entry['type'] === 'folder') {
                $all = array_merge($all, $this->collectAllEntries($fs, $entry['path']));
            }
        }

        return $all;
    }
}
