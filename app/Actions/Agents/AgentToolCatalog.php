<?php

namespace App\Actions\Agents;

use App\Mcp\ExplicateTools;
use Laravel\Mcp\Server\Tool;

class AgentToolCatalog
{
    /** @var array<string, string> */
    private const array Groups = [
        'Context' => 'Read workspace, topic, thread, post, brief, and plan context.',
        'Planning' => 'Create and maintain briefs and plans.',
        'Conversation' => 'Create, update, and delete thread posts.',
        'Files' => 'Read and write workspace files.',
        'Repositories' => 'Inspect connected repositories and run git commands.',
    ];

    /** @var array<string, string> */
    private const array ToolGroups = [
        'who-am-i' => 'Context',
        'list-workspaces' => 'Context',
        'list-topics' => 'Context',
        'list-agents' => 'Context',
        'list-agent-tasks' => 'Context',
        'get-agent-task' => 'Context',
        'get-topic' => 'Context',
        'get-agent' => 'Context',
        'list-threads' => 'Context',
        'search-threads' => 'Context',
        'get-thread' => 'Context',
        'get-post' => 'Context',
        'list-briefs' => 'Context',
        'get-brief' => 'Context',
        'get-plan' => 'Context',
        'create-topic' => 'Planning',
        'create-brief' => 'Planning',
        'update-brief' => 'Planning',
        'update-plan' => 'Planning',
        'create-thread' => 'Conversation',
        'create-post' => 'Conversation',
        'update-post' => 'Conversation',
        'delete-post' => 'Conversation',
        'list-files' => 'Files',
        'get-file' => 'Files',
        'write-file' => 'Files',
        'delete-file' => 'Files',
        'list-repos' => 'Repositories',
        'run-git-command' => 'Repositories',
    ];

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return collect(ExplicateTools::AgentTools)
            ->map(fn (string $tool): string => app($tool)->name())
            ->values()
            ->all();
    }

    /**
     * @return array<string, array{description: string, tools: list<array{name: string, description: string}>}>
     */
    public function grouped(): array
    {
        $tools = collect(ExplicateTools::AgentTools)
            ->map(function (string $tool): array {
                /** @var Tool $instance */
                $instance = app($tool);
                $name = $instance->name();

                return [
                    'name' => $name,
                    'description' => (string) $instance->description(),
                    'group' => self::ToolGroups[$name] ?? 'Context',
                ];
            })
            ->groupBy('group');

        return collect(self::Groups)
            ->mapWithKeys(fn (string $description, string $group): array => [
                $group => [
                    'description' => $description,
                    'tools' => $tools->get($group, collect())
                        ->map(fn (array $tool): array => [
                            'name' => $tool['name'],
                            'description' => $tool['description'],
                        ])
                        ->values()
                        ->all(),
                ],
            ])
            ->filter(fn (array $group): bool => $group['tools'] !== [])
            ->all();
    }

    /**
     * @param  list<string>|null  $allowedTools
     * @return list<string>
     */
    public function normalize(?array $allowedTools): array
    {
        $known = $this->names();

        if ($allowedTools === null) {
            return $known;
        }

        return collect($allowedTools)
            ->filter(fn (mixed $tool): bool => is_string($tool) && in_array($tool, $known, true))
            ->unique()
            ->values()
            ->all();
    }
}
