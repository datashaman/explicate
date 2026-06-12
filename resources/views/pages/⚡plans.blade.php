<?php

use App\Enums\TaskStatus;
use App\Models\Brief;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::workspace'), Title('Plans')] class extends Component
{
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
}; ?>

<section class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-lg border border-neutral-300 bg-white shadow-sm shadow-black/[0.04] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none" data-test="plans-page">
    <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3 dark:border-white/10">
        <div class="min-w-0">
            <flux:heading size="sm">{{ __('Plans') }}</flux:heading>
            <flux:text class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Track implementation plans generated from briefs.') }}</flux:text>
        </div>
        <flux:badge color="zinc" size="sm">{{ $this->briefs->count() }}</flux:badge>
    </div>

    <div class="min-h-0 flex-1 overflow-auto p-4">
        <div class="grid gap-3 lg:grid-cols-2 xl:grid-cols-3">
            @forelse ($this->briefs as $brief)
                <a href="{{ route('briefs.plan', $brief) }}" wire:navigate class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 transition hover:bg-neutral-100 dark:border-white/10 dark:bg-zinc-950/30 dark:hover:bg-white/5" data-test="plan-row-{{ $brief->id }}">
                    <div class="mb-3 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <flux:heading size="sm" class="truncate">{{ $brief->summary }}</flux:heading>
                            <flux:text class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                {{ $brief->sourceThread?->title ?? __('No source thread') }}
                            </flux:text>
                        </div>
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
                    </div>

                    @if ($brief->plan?->summary)
                        <p class="line-clamp-2 text-sm text-neutral-600 dark:text-neutral-300">{{ $brief->plan->summary }}</p>
                    @else
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Open this brief to create its implementation plan.') }}</p>
                    @endif
                </a>
            @empty
                <div class="col-span-full py-12 text-center">
                    <flux:text class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('No briefs yet.') }}</flux:text>
                </div>
            @endforelse
        </div>
    </div>
</section>
