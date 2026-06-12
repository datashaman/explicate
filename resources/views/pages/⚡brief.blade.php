<?php

use App\Models\Brief;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::workspace'), Title('Brief')] class extends Component
{
    public Brief $brief;

    public function mount(Brief $brief): void
    {
        abort_unless($brief->workspace_id === Auth::user()->current_workspace_id, 404);

        $this->brief = $brief->load(['plan.tasks', 'sourceThread']);
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
}; ?>

<section class="grid min-h-0 flex-1 grid-cols-1 overflow-hidden rounded-lg border border-neutral-300 bg-white shadow-sm shadow-black/[0.04] lg:grid-cols-[20rem_minmax(0,1fr)] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none" data-test="brief-show-page">
    @include('partials.brief-sidebar', ['briefs' => $this->briefs, 'selectedBriefId' => $brief->id])

    <div class="flex min-h-0 flex-col" data-test="brief-view">
        <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3 dark:border-white/10">
            <div class="min-w-0">
                <flux:heading size="sm" class="truncate">{{ $brief->summary }}</flux:heading>
                <flux:text class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Review the behaviour change before editing or planning tasks.') }}</flux:text>
            </div>

            <div class="flex shrink-0 gap-2">
                <flux:button :href="route('briefs.plan', $brief)" wire:navigate size="xs" variant="filled" icon="list-bullet" data-test="open-brief-plan">
                    {{ __('Plan') }}
                </flux:button>
                <flux:button :href="route('briefs.edit', $brief)" wire:navigate size="xs" variant="primary" icon="pencil-square" data-test="edit-brief-button">
                    {{ __('Edit') }}
                </flux:button>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-auto p-4">
            @include('partials.brief-detail', ['brief' => $brief])
        </div>
    </div>
</section>
