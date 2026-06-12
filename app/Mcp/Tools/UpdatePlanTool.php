<?php

namespace App\Mcp\Tools;

use App\Enums\TaskStatus;
use App\Mcp\Concerns\FormatsMcpPayloads;
use App\Mcp\ExplicateContext;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('update-plan')]
#[Description('Create or replace the implementation plan for a brief in the current workspace.')]
class UpdatePlanTool extends Tool
{
    use FormatsMcpPayloads;

    public function __construct(protected ExplicateContext $context) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'brief_id' => ['required', 'integer'],
            'summary' => ['nullable', 'string'],
            'tasks' => ['nullable', 'array'],
            'tasks.*.text' => ['required_with:tasks', 'string'],
            'tasks.*.expected_artifact' => ['nullable', 'string'],
            'tasks.*.status' => ['nullable', 'string', Rule::enum(TaskStatus::class)],
        ]);

        /** @var User $user */
        $user = $this->context->requireUser($request->user());
        $brief = $this->context->briefFor($user, (int) $validated['brief_id']);

        $plan = DB::transaction(function () use ($brief, $validated): Plan {
            $plan = $brief->plan()->firstOrCreate([], [
                'summary' => null,
            ]);

            if (array_key_exists('summary', $validated)) {
                $plan->update(['summary' => $validated['summary'] ?? null]);
            }

            if (array_key_exists('tasks', $validated)) {
                $plan->tasks()->delete();

                foreach (($validated['tasks'] ?? []) as $index => $task) {
                    $plan->tasks()->create([
                        'text' => $task['text'],
                        'expected_artifact' => $task['expected_artifact'] ?? null,
                        'status' => TaskStatus::from($task['status'] ?? TaskStatus::Pending->value),
                        'position' => $index + 1,
                    ]);
                }
            }

            return $plan;
        });

        $plan->refresh()->load(['brief.workspace', 'brief.sourceThread.workspace', 'tasks']);

        return Response::structured([
            'workspace' => $plan->brief->workspace->only(['id', 'name', 'slug']),
            'plan' => $this->planPayload($plan),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'brief_id' => $schema->integer()
                ->description('The brief id whose plan should be created or replaced.')
                ->required(),
            'summary' => $schema->string()
                ->description('Plan summary. Omit to leave unchanged.')
                ->nullable(),
            'tasks' => $schema->array()
                ->description('Replacement ordered task list. Omit to leave unchanged; pass [] to clear.')
                ->items($schema->object([
                    'text' => $schema->string()->required(),
                    'expected_artifact' => $schema->string()->nullable(),
                    'status' => $schema->string()->enum(TaskStatus::class)->nullable(),
                ]))
                ->nullable(),
        ];
    }
}
