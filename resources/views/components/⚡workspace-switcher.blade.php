<?php

use App\Data\UserWorkspace;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public function currentWorkspace(): ?array
    {
        $workspace = Auth::user()->currentWorkspace;

        return $workspace ? [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
        ] : null;
    }

    /**
     * @return Collection<int, UserWorkspace>
     */
    public function workspaces(): Collection
    {
        return Auth::user()->toUserWorkspaces();
    }

    public function switchWorkspace(string $slug): void
    {
        $user = Auth::user();

        $workspace = Workspace::where('slug', $slug)
            ->where('team_id', $user->currentTeam?->id)
            ->firstOrFail();

        $user->switchWorkspace($workspace);

        $this->dispatch('workspace-switched');
        $this->redirectRoute('dashboard', navigate: true);
    }
}; ?>

<div>
    <flux:dropdown position="bottom" align="start">
        <flux:button variant="ghost" class="group w-full justify-start in-data-flux-sidebar-collapsed-desktop:justify-center" data-test="workspace-switcher-trigger">
            <flux:icon name="layout-grid" class="hidden size-4 in-data-flux-sidebar-collapsed-desktop:block" />
            <span class="truncate in-data-flux-sidebar-collapsed-desktop:hidden">{{ $this->currentWorkspace()['name'] ?? __('Select workspace') }}</span>
            <flux:icon
                name="chevrons-up-down"
                variant="micro"
                class="ms-auto size-4 in-data-flux-sidebar-collapsed-desktop:hidden"
            />
        </flux:button>

        <flux:menu class="min-w-56">
            <flux:menu.heading>{{ __('Workspaces') }}</flux:menu.heading>

            @forelse ($this->workspaces() as $workspace)
                <flux:menu.item
                    wire:click="switchWorkspace('{{ $workspace->slug }}')"
                    class="cursor-pointer"
                    data-test="workspace-switcher-item"
                >
                    <div class="flex w-full items-center justify-between">
                        <span>{{ $workspace->name }}</span>
                        @if ($workspace->isCurrent)
                            <flux:icon name="check" class="size-4" />
                        @endif
                    </div>
                </flux:menu.item>
            @empty
                <flux:menu.item disabled>
                    {{ __('No workspaces') }}
                </flux:menu.item>
            @endforelse

            <flux:menu.separator />

            <flux:modal.trigger name="create-workspace-switcher">
                <flux:menu.item icon="plus" class="cursor-pointer" data-test="workspace-switcher-new-workspace">
                    {{ __('New workspace') }}
                </flux:menu.item>
            </flux:modal.trigger>
        </flux:menu>
    </flux:dropdown>
</div>
