<?php

namespace App\Mcp\Tools;

use App\Enums\BriefCategory;
use App\Mcp\Concerns\FormatsMcpPayloads;
use App\Mcp\ExplicateContext;
use App\Models\Brief;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create-brief')]
#[Description('Create a bug or feature brief in the current workspace.')]
class CreateBriefTool extends Tool
{
    use FormatsMcpPayloads;

    public function __construct(protected ExplicateContext $context) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'category' => ['required', 'string', Rule::enum(BriefCategory::class)],
            'summary' => ['required', 'string', 'max:255'],
            'current_behaviour' => ['required', 'string'],
            'expected_behaviour' => ['required', 'string'],
            'acceptance_criteria' => ['nullable', 'array'],
            'acceptance_criteria.*.text' => ['required_with:acceptance_criteria', 'string'],
            'acceptance_criteria.*.done' => ['nullable', 'boolean'],
            'out_of_scope' => ['nullable', 'string'],
            'source_thread' => ['nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $workspace = $this->context->workspaceFor($user);
        $sourceThread = filled($validated['source_thread'] ?? null)
            ? $this->context->threadFor($user, $validated['source_thread'])
            : null;

        $brief = Brief::query()->create([
            'workspace_id' => $workspace->id,
            'source_thread_id' => $sourceThread?->id,
            'category' => BriefCategory::from($validated['category']),
            'summary' => $validated['summary'],
            'current_behaviour' => $validated['current_behaviour'],
            'expected_behaviour' => $validated['expected_behaviour'],
            'acceptance_criteria' => $this->acceptanceCriteria($validated['acceptance_criteria'] ?? []),
            'out_of_scope' => $validated['out_of_scope'] ?? null,
        ]);
        $brief->load(['workspace', 'sourceThread.workspace', 'plan.tasks']);

        return Response::structured([
            'workspace' => $workspace->only(['id', 'name', 'slug']),
            'brief' => $this->briefPayload($brief),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()
                ->description('The brief category.')
                ->enum(BriefCategory::class)
                ->required(),
            'summary' => $schema->string()
                ->description('One-line brief summary.')
                ->required(),
            'current_behaviour' => $schema->string()
                ->description('The current behaviour or problem state.')
                ->required(),
            'expected_behaviour' => $schema->string()
                ->description('The desired behaviour or outcome.')
                ->required(),
            'acceptance_criteria' => $schema->array()
                ->description('Optional checklist items shaped as {text, done}.')
                ->items($schema->object([
                    'text' => $schema->string()->required(),
                    'done' => $schema->boolean(),
                ]))
                ->nullable(),
            'out_of_scope' => $schema->string()
                ->description('Optional explicit exclusions.')
                ->nullable(),
            'source_thread' => $schema->string()
                ->description('Optional source thread slug, id, URI, or URL reference.')
                ->nullable(),
        ];
    }

    /**
     * @param  list<array{text: string, done?: bool}>  $criteria
     * @return list<array{text: string, done: bool}>
     */
    private function acceptanceCriteria(array $criteria): array
    {
        return collect($criteria)
            ->map(fn (array $criterion): array => [
                'text' => $criterion['text'],
                'done' => (bool) ($criterion['done'] ?? false),
            ])
            ->values()
            ->all();
    }
}
