<?php

use App\Models\Brief;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::workspace'), Title('Briefs')] class extends Component
{
    public ?int $selectedBriefId = null;

    public function mount(): void
    {
        $firstBrief = $this->briefs()->first();

        if ($firstBrief) {
            $this->redirectRoute('briefs.show', $firstBrief, navigate: true);

            return;
        }

        $this->selectedBriefId = null;
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

    public function selectedBrief(): ?Brief
    {
        $workspace = $this->workspace();

        if (! $workspace || ! $this->selectedBriefId) {
            return null;
        }

        return $workspace->briefs()
            ->with(['plan.tasks', 'sourceThread'])
            ->whereKey($this->selectedBriefId)
            ->first();
    }

}; ?>

<section class="grid min-h-0 flex-1 grid-cols-1 overflow-hidden rounded-lg border border-neutral-300 bg-white shadow-sm shadow-black/[0.04] lg:grid-cols-[20rem_minmax(0,1fr)] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none" data-test="briefs-page">
    @include('partials.brief-sidebar', ['briefs' => $this->briefs, 'selectedBriefId' => $selectedBriefId])

    @php
        $selectedBrief = $this->selectedBrief();
    @endphp

    <div class="flex min-h-0 flex-col" data-test="brief-view">
        <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3 dark:border-white/10">
            <div class="min-w-0">
                <flux:heading size="sm" class="truncate">{{ $selectedBrief?->summary ?? __('No brief selected') }}</flux:heading>
                <flux:text class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Review the behaviour change before editing or planning tasks.') }}</flux:text>
            </div>

            @if ($selectedBrief)
                <div class="flex shrink-0 gap-2">
                    <flux:button :href="route('briefs.plan', $selectedBrief)" wire:navigate size="xs" variant="filled" icon="list-bullet" data-test="open-brief-plan">
                        {{ __('Plan') }}
                    </flux:button>
                    <flux:button :href="route('briefs.edit', $selectedBrief)" wire:navigate size="xs" variant="primary" icon="pencil-square" data-test="edit-brief-button">
                        {{ __('Edit') }}
                    </flux:button>
                </div>
            @endif
        </div>

        <div class="min-h-0 flex-1 overflow-auto p-4">
            @if ($selectedBrief)
                @include('partials.brief-detail', ['brief' => $selectedBrief])
            @else
                <div class="py-16 text-center">
                    <flux:heading size="sm">{{ __('No briefs yet.') }}</flux:heading>
                    <flux:button :href="route('briefs.create')" wire:navigate class="mt-4" variant="primary" icon="plus">{{ __('New brief') }}</flux:button>
                </div>
            @endif
        </div>
    </div>
</section>
