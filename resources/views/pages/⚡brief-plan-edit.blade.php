<?php

use App\Enums\TaskStatus;
use App\Models\Brief;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::workspace'), Title('Plan')] class extends Component
{
    public Brief $brief;

    public string $planSummary = '';

    /** @var list<array{id: int|null, text: string, expected_artifact: string, status: string, position: int}> */
    public array $planTasks = [];

    public string $newPlanTask = '';

    public function mount(Brief $brief): void
    {
        abort_unless($brief->workspace_id === Auth::user()->current_workspace_id, 404);

        $this->brief = $brief->load(['sourceThread', 'plan.tasks']);
        $this->loadPlanFields();
    }

    public function savePlan(): void
    {
        $validated = $this->validate([
            'planSummary' => ['nullable', 'string'],
            'planTasks' => ['array'],
            'planTasks.*.id' => ['nullable', 'integer'],
            'planTasks.*.text' => ['required', 'string', 'max:1000'],
            'planTasks.*.expected_artifact' => ['nullable', 'string'],
            'planTasks.*.status' => ['required', Rule::enum(TaskStatus::class)],
            'planTasks.*.position' => ['required', 'integer', 'min:1'],
        ], [], [
            'planSummary' => __('plan summary'),
            'planTasks.*.text' => __('task'),
        ]);

        $plan = $this->brief->plan()->firstOrCreate();
        $plan->forceFill([
            'summary' => $validated['planSummary'] ?: null,
        ])->save();

        $tasks = $this->normalizePlanTasks($validated['planTasks'] ?? []);
        $keptTaskIds = [];

        foreach ($tasks as $task) {
            $model = isset($task['id'])
                ? $plan->tasks()->whereKey($task['id'])->first()
                : null;

            $model ??= new Task(['plan_id' => $plan->id]);
            $model->forceFill([
                'text' => $task['text'],
                'expected_artifact' => $task['expected_artifact'] ?: null,
                'status' => $task['status'],
                'position' => $task['position'],
            ])->save();

            $keptTaskIds[] = $model->id;
        }

        $plan->tasks()
            ->when($keptTaskIds !== [], fn ($query) => $query->whereNotIn('id', $keptTaskIds))
            ->delete();

        $this->brief->refresh();
        $this->loadPlanFields();

        Flux::toast(variant: 'success', text: __('Plan saved.'));
    }

    public function addPlanTask(): void
    {
        $text = trim($this->newPlanTask);

        if ($text === '') {
            $this->addError('newPlanTask', __('Enter a task.'));

            return;
        }

        $this->planTasks[] = [
            'id' => null,
            'text' => $text,
            'expected_artifact' => '',
            'status' => TaskStatus::Pending->value,
            'position' => count($this->planTasks) + 1,
        ];
        $this->newPlanTask = '';
        $this->resetErrorBag('newPlanTask');
    }

    public function removePlanTask(int $index): void
    {
        unset($this->planTasks[$index]);
        $this->planTasks = $this->normalizePlanTasks(array_values($this->planTasks));
    }

    public function movePlanTaskUp(int $index): void
    {
        if ($index <= 0 || ! isset($this->planTasks[$index])) {
            return;
        }

        [$this->planTasks[$index - 1], $this->planTasks[$index]] = [$this->planTasks[$index], $this->planTasks[$index - 1]];
        $this->planTasks = $this->normalizePlanTasks($this->planTasks);
    }

    public function movePlanTaskDown(int $index): void
    {
        if (! isset($this->planTasks[$index], $this->planTasks[$index + 1])) {
            return;
        }

        [$this->planTasks[$index], $this->planTasks[$index + 1]] = [$this->planTasks[$index + 1], $this->planTasks[$index]];
        $this->planTasks = $this->normalizePlanTasks($this->planTasks);
    }

    private function loadPlanFields(): void
    {
        $plan = $this->brief->plan()->with('tasks')->first();

        $this->planSummary = $plan?->summary ?? '';
        $this->planTasks = $this->normalizePlanTasks(
            $plan?->tasks
                ->map(fn (Task $task): array => [
                    'id' => $task->id,
                    'text' => $task->text,
                    'expected_artifact' => $task->expected_artifact ?? '',
                    'status' => ($task->status ?? TaskStatus::Pending)->value,
                    'position' => $task->position,
                ])
                ->all() ?? []
        );
        $this->newPlanTask = '';
    }

    /**
     * @param  array<int, mixed>  $tasks
     * @return list<array{id: int|null, text: string, expected_artifact: string, status: string, position: int}>
     */
    private function normalizePlanTasks(array $tasks): array
    {
        return collect($tasks)
            ->map(function (mixed $task): array {
                $status = TaskStatus::tryFrom((string) ($task['status'] ?? '')) ?? TaskStatus::Pending;

                return [
                    'id' => isset($task['id']) ? (int) $task['id'] : null,
                    'text' => trim((string) ($task['text'] ?? '')),
                    'expected_artifact' => trim((string) ($task['expected_artifact'] ?? '')),
                    'status' => $status->value,
                    'position' => (int) ($task['position'] ?? 0),
                ];
            })
            ->filter(fn (array $task): bool => $task['text'] !== '')
            ->values()
            ->map(function (array $task, int $index): array {
                $task['position'] = $index + 1;

                return $task;
            })
            ->all();
    }
}; ?>

<section class="grid min-h-0 flex-1 grid-cols-1 overflow-hidden rounded-lg border border-neutral-300 bg-white shadow-sm shadow-black/[0.04] xl:grid-cols-[26rem_minmax(0,1fr)] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none" data-test="brief-plan-page">
    <aside class="min-h-0 overflow-auto border-b border-neutral-200 p-4 xl:border-b-0 xl:border-r dark:border-white/10">
        <div class="mb-4 flex items-center justify-between gap-3">
            <flux:button :href="route('briefs')" wire:navigate size="xs" variant="filled" icon="arrow-left" data-test="back-to-briefs">
                {{ __('Briefs') }}
            </flux:button>
            <flux:badge color="zinc" size="sm">{{ $brief->category->label() }}</flux:badge>
        </div>

        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ $brief->summary }}</flux:heading>
                @if ($brief->sourceThread)
                    <flux:text class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Source') }}: {{ $brief->sourceThread->title }}</flux:text>
                @endif
            </div>

            <div>
                <flux:heading size="sm">{{ __('Expected behaviour') }}</flux:heading>
                <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-300">{{ $brief->expected_behaviour }}</p>
            </div>

            <div>
                <flux:heading size="sm">{{ __('Acceptance criteria') }}</flux:heading>
                <div class="mt-2 space-y-2">
                    @forelse ($brief->acceptance_criteria as $criterion)
                        <div class="rounded-md border border-neutral-200 bg-neutral-50 p-2 text-sm text-neutral-700 dark:border-white/10 dark:bg-zinc-950/30 dark:text-neutral-200">
                            {{ $criterion['text'] ?? '' }}
                        </div>
                    @empty
                        <flux:text class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('No acceptance criteria.') }}</flux:text>
                    @endforelse
                </div>
            </div>
        </div>
    </aside>

    <form wire:submit="savePlan" class="flex min-h-0 flex-col" data-test="brief-plan-form">
        <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3 dark:border-white/10">
            <div class="min-w-0">
                <flux:heading size="sm">{{ __('Plan') }}</flux:heading>
                <flux:text class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Break the brief into ordered implementation tasks.') }}</flux:text>
            </div>
            <flux:badge color="zinc" size="sm">{{ count($planTasks) }}</flux:badge>
        </div>

        <div class="min-h-0 flex-1 overflow-auto p-4">
            <div class="w-full space-y-4">
                <flux:textarea wire:model="planSummary" :label="__('Summary')" rows="4" data-test="plan-summary" />

                <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 dark:border-white/10 dark:bg-zinc-950/30">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <flux:heading size="sm">{{ __('Tasks') }}</flux:heading>
                        <flux:badge color="zinc" size="sm">{{ count($planTasks) }}</flux:badge>
                    </div>

                    <div class="space-y-2" data-test="plan-task-list">
                        @forelse ($planTasks as $index => $task)
                            <div class="grid gap-2 rounded-md border border-neutral-200 bg-white p-2 md:grid-cols-[minmax(0,1.2fr)_minmax(12rem,0.8fr)_10rem_auto] dark:border-white/10 dark:bg-zinc-900" wire:key="plan-task-{{ $task['id'] ?? 'new-'.$index }}">
                                <flux:input wire:model="planTasks.{{ $index }}.text" type="text" class="min-w-0" data-test="plan-task-text-{{ $index }}" />
                                <flux:input wire:model="planTasks.{{ $index }}.expected_artifact" type="text" :placeholder="__('Expected artifact')" class="min-w-0" data-test="plan-task-artifact-{{ $index }}" />
                                <flux:select wire:model="planTasks.{{ $index }}.status" data-test="plan-task-status-{{ $index }}">
                                    @foreach (TaskStatus::cases() as $status)
                                        <flux:select.option value="{{ $status->value }}">{{ $status->label() }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <div class="flex shrink-0 justify-end gap-1">
                                    <flux:button type="button" wire:click="movePlanTaskUp({{ $index }})" size="xs" variant="ghost" icon="arrow-up" data-test="plan-task-up-{{ $index }}" />
                                    <flux:button type="button" wire:click="movePlanTaskDown({{ $index }})" size="xs" variant="ghost" icon="arrow-down" data-test="plan-task-down-{{ $index }}" />
                                    <flux:button type="button" wire:click="removePlanTask({{ $index }})" size="xs" variant="ghost" icon="x-mark" data-test="plan-task-remove-{{ $index }}" />
                                </div>
                            </div>
                        @empty
                            <flux:text class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('No plan tasks yet.') }}</flux:text>
                        @endforelse
                    </div>

                    <div class="mt-3 flex gap-2">
                        <flux:input wire:model="newPlanTask" type="text" :placeholder="__('Add task…')" data-test="new-plan-task" />
                        <flux:button type="button" wire:click="addPlanTask" size="sm" icon="plus" data-test="add-plan-task">{{ __('Add') }}</flux:button>
                    </div>
                    @error('newPlanTask')
                        <div class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-2 border-t border-neutral-200 bg-neutral-50/80 px-4 py-3 dark:border-white/10 dark:bg-zinc-950/40">
            <flux:button :href="route('briefs.plan', $brief)" wire:navigate variant="filled">{{ __('Back') }}</flux:button>
            <flux:button type="submit" variant="primary" icon="check" data-test="plan-save">{{ __('Save plan') }}</flux:button>
        </div>
    </form>
</section>
