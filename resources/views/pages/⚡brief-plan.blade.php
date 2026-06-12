<?php

use App\Enums\TaskStatus;
use App\Models\Brief;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::workspace'), Title('Plan')] class extends Component
{
    public Brief $brief;

    public function mount(Brief $brief): void
    {
        abort_unless($brief->workspace_id === Auth::user()->current_workspace_id, 404);

        $this->brief = $brief->load(['sourceThread', 'plan.tasks']);
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
                <p class="mt-1 whitespace-pre-wrap text-sm text-neutral-600 dark:text-neutral-300">{{ $brief->expected_behaviour }}</p>
            </div>

            <div>
                <flux:heading size="sm">{{ __('Acceptance criteria') }}</flux:heading>
                <div class="mt-2 space-y-2">
                    @forelse ($brief->acceptance_criteria ?? [] as $criterion)
                        <div class="rounded-md border border-neutral-200 bg-neutral-50 p-2 text-sm text-neutral-700 dark:border-white/10 dark:bg-zinc-950/30 dark:text-neutral-200">
                            <span @class(['line-through text-neutral-400 dark:text-neutral-500' => (bool) ($criterion['done'] ?? false)])>{{ $criterion['text'] ?? '' }}</span>
                        </div>
                    @empty
                        <flux:text class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('No acceptance criteria.') }}</flux:text>
                    @endforelse
                </div>
            </div>
        </div>
    </aside>

    <div class="flex min-h-0 flex-col" data-test="brief-plan-view">
        <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3 dark:border-white/10">
            <div class="min-w-0">
                <flux:heading size="sm">{{ __('Plan') }}</flux:heading>
                <flux:text class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Review the implementation plan before editing tasks.') }}</flux:text>
            </div>
            <flux:button :href="route('briefs.plan.edit', $brief)" wire:navigate size="xs" variant="primary" icon="pencil-square" data-test="edit-plan-button">
                {{ __('Edit') }}
            </flux:button>
        </div>

        <div class="min-h-0 flex-1 overflow-auto p-4">
            <div class="w-full space-y-4">
                <section class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-white/10 dark:bg-zinc-900">
                    <flux:heading size="sm">{{ __('Summary') }}</flux:heading>
                    @if ($brief->plan?->summary)
                        <p class="mt-2 whitespace-pre-wrap text-sm text-neutral-700 dark:text-neutral-200">{{ $brief->plan->summary }}</p>
                    @else
                        <flux:text class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('No plan summary yet.') }}</flux:text>
                    @endif
                </section>

                <section class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 dark:border-white/10 dark:bg-zinc-950/30">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <flux:heading size="sm">{{ __('Tasks') }}</flux:heading>
                        @php
                            $tasks = $brief->plan?->tasks ?? collect();
                            $taskCount = $tasks->count();
                            $doneTaskCount = $tasks->where('status', TaskStatus::Done)->count();
                        @endphp
                        <flux:badge :color="$taskCount > 0 && $doneTaskCount === $taskCount ? 'green' : 'blue'" size="sm">
                            {{ __(':done/:total done', ['done' => $doneTaskCount, 'total' => $taskCount]) }}
                        </flux:badge>
                    </div>

                    <div class="space-y-2" data-test="plan-task-list">
                        @forelse ($tasks as $task)
                            @php
                                $status = $task->status ?? TaskStatus::Pending;
                            @endphp
                            <div class="rounded-md border border-neutral-200 bg-white p-3 dark:border-white/10 dark:bg-zinc-900" data-test="plan-task-row-{{ $task->id }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="text-sm font-medium text-neutral-800 dark:text-neutral-100">{{ $task->text }}</div>
                                        @if ($task->expected_artifact)
                                            <div class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{{ __('Artifact') }}: {{ $task->expected_artifact }}</div>
                                        @endif
                                    </div>
                                    <flux:badge :color="$status->color()" size="sm">{{ $status->label() }}</flux:badge>
                                </div>
                            </div>
                        @empty
                            <flux:text class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('No plan tasks yet.') }}</flux:text>
                        @endforelse
                    </div>
                </section>
            </div>
        </div>
    </div>
</section>
