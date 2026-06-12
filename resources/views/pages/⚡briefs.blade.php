<?php

use App\Enums\BriefCategory;
use App\Enums\TaskStatus;
use App\Models\Brief;
use App\Models\Thread;
use App\Models\Workspace;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::workspace'), Title('Briefs')] class extends Component
{
    public ?int $selectedBriefId = null;

    public string $category = 'feature';

    public string $summary = '';

    public string $currentBehaviour = '';

    public string $expectedBehaviour = '';

    public string $outOfScope = '';

    public ?int $sourceThreadId = null;

    /** @var list<array{text: string, done: bool}> */
    public array $acceptanceCriteria = [];

    public string $newAcceptanceCriterion = '';

    public function mount(): void
    {
        $firstBrief = $this->briefs()->first();

        if ($firstBrief) {
            $this->selectBrief($firstBrief->id);
        }
    }

    public function workspace(): ?Workspace
    {
        return Auth::user()->currentWorkspace;
    }

    /**
     * @return Collection<int, Brief>
     */
    #[Computed]
    public function briefs(): Collection
    {
        $workspace = $this->workspace();

        if (! $workspace) {
            return Brief::query()->whereNull('id')->get();
        }

        return $workspace->briefs()
            ->with(['plan.tasks', 'sourceThread'])
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * @return Collection<int, Thread>
     */
    #[Computed]
    public function sourceThreads(): Collection
    {
        $workspace = $this->workspace();

        if (! $workspace) {
            return Thread::query()->whereNull('id')->get();
        }

        return $workspace->threads()
            ->with('topic')
            ->reorder()
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();
    }

    public function selectedBrief(): ?Brief
    {
        $workspace = $this->workspace();

        if (! $workspace || ! $this->selectedBriefId) {
            return null;
        }

        return $workspace->briefs()
            ->with('sourceThread')
            ->whereKey($this->selectedBriefId)
            ->first();
    }

    public function startNewBrief(): void
    {
        $this->selectedBriefId = null;
        $this->category = BriefCategory::Feature->value;
        $this->summary = '';
        $this->currentBehaviour = '';
        $this->expectedBehaviour = '';
        $this->outOfScope = '';
        $this->sourceThreadId = null;
        $this->acceptanceCriteria = [];
        $this->newAcceptanceCriterion = '';
        $this->resetValidation();
    }

    public function selectBrief(int $briefId): void
    {
        $workspace = $this->workspace();
        abort_unless($workspace, 403);

        $brief = $workspace->briefs()->findOrFail($briefId);

        $this->selectedBriefId = $brief->id;
        $this->category = $brief->category->value;
        $this->summary = $brief->summary;
        $this->currentBehaviour = $brief->current_behaviour;
        $this->expectedBehaviour = $brief->expected_behaviour;
        $this->outOfScope = $brief->out_of_scope ?? '';
        $this->sourceThreadId = $brief->source_thread_id;
        $this->acceptanceCriteria = $this->normalizeAcceptanceCriteria($brief->acceptance_criteria ?? []);
        $this->newAcceptanceCriterion = '';
        $this->resetValidation();
    }

    public function saveBrief(): void
    {
        $workspace = $this->workspace();
        abort_unless($workspace, 403);

        $validated = $this->validate([
            'category' => ['required', Rule::enum(BriefCategory::class)],
            'summary' => ['required', 'string', 'max:255'],
            'currentBehaviour' => ['required', 'string'],
            'expectedBehaviour' => ['required', 'string'],
            'outOfScope' => ['nullable', 'string'],
            'sourceThreadId' => ['nullable', 'integer', Rule::exists('threads', 'id')->where('workspace_id', $workspace->id)],
            'acceptanceCriteria' => ['array'],
            'acceptanceCriteria.*.text' => ['required', 'string', 'max:1000'],
            'acceptanceCriteria.*.done' => ['boolean'],
        ], [], [
            'currentBehaviour' => __('current behaviour'),
            'expectedBehaviour' => __('expected behaviour'),
            'sourceThreadId' => __('source thread'),
            'acceptanceCriteria.*.text' => __('acceptance criterion'),
        ]);

        $brief = $this->selectedBrief();

        if (! $brief) {
            $brief = new Brief;
            $brief->workspace_id = $workspace->id;
        }

        $brief->fill([
            'source_thread_id' => $validated['sourceThreadId'] ?? null,
            'category' => $validated['category'],
            'summary' => $validated['summary'],
            'current_behaviour' => $validated['currentBehaviour'],
            'expected_behaviour' => $validated['expectedBehaviour'],
            'out_of_scope' => $validated['outOfScope'] ?: null,
            'acceptance_criteria' => $this->normalizeAcceptanceCriteria($validated['acceptanceCriteria'] ?? []),
        ]);

        $brief->save();

        $this->selectBrief($brief->id);

        Flux::toast(variant: 'success', text: __('Brief saved.'));
    }

    public function deleteBrief(): void
    {
        $brief = $this->selectedBrief();
        abort_unless($brief, 404);

        $brief->delete();
        $this->startNewBrief();

        Flux::toast(variant: 'success', text: __('Brief deleted.'));
    }

    public function addAcceptanceCriterion(): void
    {
        $text = trim($this->newAcceptanceCriterion);

        if ($text === '') {
            $this->addError('newAcceptanceCriterion', __('Enter an acceptance criterion.'));

            return;
        }

        $this->acceptanceCriteria[] = ['text' => $text, 'done' => false];
        $this->newAcceptanceCriterion = '';
        $this->resetErrorBag('newAcceptanceCriterion');
    }

    public function removeAcceptanceCriterion(int $index): void
    {
        unset($this->acceptanceCriteria[$index]);
        $this->acceptanceCriteria = array_values($this->acceptanceCriteria);
    }

    public function toggleAcceptanceCriterion(int $index): void
    {
        if (! isset($this->acceptanceCriteria[$index])) {
            return;
        }

        $this->acceptanceCriteria[$index]['done'] = ! (bool) $this->acceptanceCriteria[$index]['done'];
    }

    /**
     * @param  array<int, mixed>  $criteria
     * @return list<array{text: string, done: bool}>
     */
    private function normalizeAcceptanceCriteria(array $criteria): array
    {
        return collect($criteria)
            ->map(fn (mixed $criterion): array => [
                'text' => trim((string) ($criterion['text'] ?? '')),
                'done' => (bool) ($criterion['done'] ?? false),
            ])
            ->filter(fn (array $criterion): bool => $criterion['text'] !== '')
            ->values()
            ->all();
    }

}; ?>

<section class="grid min-h-0 flex-1 grid-cols-1 overflow-hidden rounded-lg border border-neutral-300 bg-white shadow-sm shadow-black/[0.04] lg:grid-cols-[20rem_minmax(0,1fr)] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none" data-test="briefs-page">
    <aside class="flex min-h-0 flex-col border-b border-neutral-200 lg:border-b-0 lg:border-r dark:border-white/10">
        <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3 dark:border-white/10">
            <flux:heading size="sm">{{ __('Briefs') }}</flux:heading>
            <flux:button wire:click="startNewBrief" size="xs" icon="plus" variant="primary" data-test="new-brief-button">
                {{ __('New brief') }}
            </flux:button>
        </div>

        <div class="min-h-0 flex-1 overflow-auto p-2">
            @forelse ($this->briefs as $brief)
                <button
                    type="button"
                    wire:click="selectBrief({{ $brief->id }})"
                    @class([
                        'flex w-full items-start gap-3 rounded-lg px-3 py-2 text-left transition',
                        'bg-emerald-50 dark:bg-emerald-500/10' => $selectedBriefId === $brief->id,
                        'hover:bg-neutral-100 dark:hover:bg-white/5' => $selectedBriefId !== $brief->id,
                    ])
                    wire:key="brief-row-{{ $brief->id }}"
                    data-test="brief-row-{{ $brief->id }}"
                >
                    <flux:icon name="{{ $brief->category === BriefCategory::Bug ? 'bug-ant' : 'sparkles' }}" class="mt-0.5 size-4 shrink-0 text-neutral-500 dark:text-neutral-400" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-neutral-800 dark:text-neutral-100">{{ $brief->summary }}</span>
                        <span class="mt-1 flex items-center gap-2 text-xs text-neutral-500 dark:text-neutral-400">
                            <span>{{ $brief->category->label() }}</span>
                            @if ($brief->sourceThread)
                                <span class="truncate">{{ $brief->sourceThread->title }}</span>
                            @endif
                        </span>
                        <span class="mt-2 inline-flex">
                            @if ($brief->plan)
                                @php
                                    $taskCount = $brief->plan->tasks->count();
                                    $doneTaskCount = $brief->plan->tasks->where('status', TaskStatus::Done)->count();
                                @endphp
                                <flux:badge :color="$taskCount > 0 && $doneTaskCount === $taskCount ? 'green' : 'blue'" size="sm">
                                    {{ __(':done/:total done', ['done' => $doneTaskCount, 'total' => $taskCount]) }}
                                </flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">{{ __('No plan') }}</flux:badge>
                            @endif
                        </span>
                    </span>
                </button>
            @empty
                <div class="px-3 py-8 text-center">
                    <flux:text class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('No briefs yet.') }}</flux:text>
                </div>
            @endforelse
        </div>
    </aside>

    <form wire:submit="saveBrief" class="flex min-h-0 flex-col" data-test="brief-form">
        <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3 dark:border-white/10">
            <div class="min-w-0">
                <flux:heading size="sm" class="truncate">{{ $selectedBriefId ? __('Edit brief') : __('New brief') }}</flux:heading>
                <flux:text class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Capture the behaviour change before planning tasks.') }}</flux:text>
            </div>

            @if ($selectedBriefId)
                <div class="flex shrink-0 gap-2">
                    <flux:button :href="route('briefs.plan', $selectedBriefId)" wire:navigate size="xs" variant="filled" icon="list-bullet" data-test="open-brief-plan">
                        {{ __('Plan') }}
                    </flux:button>
                    <flux:button type="button" wire:click="deleteBrief" wire:confirm="{{ __('Delete this brief?') }}" size="xs" variant="danger" icon="trash" data-test="delete-brief-button">
                        {{ __('Delete') }}
                    </flux:button>
                </div>
            @endif
        </div>

        <div class="min-h-0 flex-1 overflow-auto px-4 py-4">
            <div class="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(22rem,1fr)]">
                <div class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-[12rem_minmax(0,1fr)]">
                        <flux:select wire:model="category" :label="__('Category')" data-test="brief-category">
                            @foreach (BriefCategory::cases() as $briefCategory)
                                <flux:select.option value="{{ $briefCategory->value }}">{{ $briefCategory->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input wire:model="summary" :label="__('Summary')" type="text" required data-test="brief-summary" />
                    </div>

                    <flux:textarea wire:model="currentBehaviour" :label="__('Current behaviour')" rows="5" required data-test="brief-current-behaviour" />
                    <flux:textarea wire:model="expectedBehaviour" :label="__('Expected behaviour')" rows="5" required data-test="brief-expected-behaviour" />
                    <flux:textarea wire:model="outOfScope" :label="__('Out of scope')" rows="4" data-test="brief-out-of-scope" />
                </div>

                <div class="space-y-4">
                    <flux:select wire:model="sourceThreadId" :label="__('Source thread')" data-test="brief-source-thread">
                        <flux:select.option value="">{{ __('None') }}</flux:select.option>
                        @foreach ($this->sourceThreads as $thread)
                            <flux:select.option value="{{ $thread->id }}">{{ $thread->title }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 dark:border-white/10 dark:bg-zinc-950/30">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <flux:heading size="sm">{{ __('Acceptance criteria') }}</flux:heading>
                            <flux:badge color="zinc" size="sm">{{ count($acceptanceCriteria) }}</flux:badge>
                        </div>

                        <div class="space-y-2" data-test="brief-acceptance-list">
                            @forelse ($acceptanceCriteria as $index => $criterion)
                                <div class="flex items-start gap-2 rounded-md border border-neutral-200 bg-white p-2 dark:border-white/10 dark:bg-zinc-900" wire:key="acceptance-criterion-{{ $index }}">
                                    <flux:checkbox wire:model.live="acceptanceCriteria.{{ $index }}.done" data-test="brief-acceptance-toggle-{{ $index }}" />
                                    <div class="min-w-0 flex-1 text-sm text-neutral-700 dark:text-neutral-200 @if ($criterion['done']) line-through text-neutral-400 dark:text-neutral-500 @endif">
                                        {{ $criterion['text'] }}
                                    </div>
                                    <flux:button type="button" wire:click="removeAcceptanceCriterion({{ $index }})" size="xs" variant="ghost" icon="x-mark" data-test="brief-acceptance-remove-{{ $index }}" />
                                </div>
                            @empty
                                <flux:text class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('No acceptance criteria.') }}</flux:text>
                            @endforelse
                        </div>

                        <div class="mt-3 flex gap-2">
                            <flux:input wire:model="newAcceptanceCriterion" type="text" :placeholder="__('Add criterion…')" data-test="brief-new-acceptance" />
                            <flux:button type="button" wire:click="addAcceptanceCriterion" size="sm" icon="plus" data-test="brief-add-acceptance">{{ __('Add') }}</flux:button>
                        </div>
                        @error('newAcceptanceCriterion')
                            <div class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-2 border-t border-neutral-200 bg-neutral-50/80 px-4 py-3 dark:border-white/10 dark:bg-zinc-950/40">
            <flux:button type="button" wire:click="startNewBrief" variant="filled">{{ __('Reset') }}</flux:button>
            <flux:button type="submit" variant="primary" icon="check" data-test="brief-save">{{ __('Save brief') }}</flux:button>
        </div>
    </form>
</section>
