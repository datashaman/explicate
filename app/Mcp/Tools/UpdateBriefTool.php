<?php

namespace App\Mcp\Tools;

use App\Enums\BriefCategory;
use App\Mcp\Concerns\FormatsMcpPayloads;
use App\Mcp\ExplicateContext;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('update-brief')]
#[Description('Update a brief in the current workspace. Omitted fields are left unchanged.')]
class UpdateBriefTool extends Tool
{
    use FormatsMcpPayloads;

    public function __construct(protected ExplicateContext $context) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'brief_id' => ['required', 'integer'],
            'category' => ['nullable', 'string', Rule::enum(BriefCategory::class)],
            'summary' => ['nullable', 'string', 'max:255'],
            'current_behaviour' => ['nullable', 'string'],
            'expected_behaviour' => ['nullable', 'string'],
            'acceptance_criteria' => ['nullable', 'array'],
            'acceptance_criteria.*.text' => ['required_with:acceptance_criteria', 'string'],
            'acceptance_criteria.*.done' => ['nullable', 'boolean'],
            'out_of_scope' => ['nullable', 'string'],
            'source_thread' => ['nullable', 'string'],
            'clear_source_thread' => ['nullable', 'boolean'],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $brief = $this->context->briefFor($user, (int) $validated['brief_id']);

        $attributes = collect([
            'category' => isset($validated['category']) ? BriefCategory::from($validated['category']) : null,
            'summary' => $validated['summary'] ?? null,
            'current_behaviour' => $validated['current_behaviour'] ?? null,
            'expected_behaviour' => $validated['expected_behaviour'] ?? null,
            'acceptance_criteria' => array_key_exists('acceptance_criteria', $validated)
                ? $this->acceptanceCriteria($validated['acceptance_criteria'] ?? [])
                : null,
            'out_of_scope' => array_key_exists('out_of_scope', $validated) ? $validated['out_of_scope'] : null,
        ])->filter(fn ($value): bool => $value !== null)->all();

        if (($validated['clear_source_thread'] ?? false) === true) {
            $attributes['source_thread_id'] = null;
        } elseif (filled($validated['source_thread'] ?? null)) {
            $attributes['source_thread_id'] = $this->context->threadFor($user, $validated['source_thread'])->id;
        }

        $brief->update($attributes);
        $brief->refresh()->load(['workspace', 'sourceThread.workspace', 'plan.tasks']);

        return Response::structured([
            'workspace' => $brief->workspace->only(['id', 'name', 'slug']),
            'brief' => $this->briefPayload($brief),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'brief_id' => $schema->integer()
                ->description('The brief id to update.')
                ->required(),
            'category' => $schema->string()
                ->description('New brief category. Omit to leave unchanged.')
                ->enum(BriefCategory::class)
                ->nullable(),
            'summary' => $schema->string()
                ->description('New one-line summary. Omit to leave unchanged.')
                ->nullable(),
            'current_behaviour' => $schema->string()
                ->description('New current behaviour text. Omit to leave unchanged.')
                ->nullable(),
            'expected_behaviour' => $schema->string()
                ->description('New expected behaviour text. Omit to leave unchanged.')
                ->nullable(),
            'acceptance_criteria' => $schema->array()
                ->description('Replacement checklist shaped as {text, done}. Omit to leave unchanged.')
                ->items($schema->object([
                    'text' => $schema->string()->required(),
                    'done' => $schema->boolean(),
                ]))
                ->nullable(),
            'out_of_scope' => $schema->string()
                ->description('New out-of-scope text. Omit to leave unchanged.')
                ->nullable(),
            'source_thread' => $schema->string()
                ->description('New source thread slug, id, URI, or URL reference. Omit to leave unchanged.')
                ->nullable(),
            'clear_source_thread' => $schema->boolean()
                ->description('Set true to remove the source thread reference.')
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
