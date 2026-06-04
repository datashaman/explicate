<?php

use App\Actions\Workspaces\CreateWorkspace;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public string $workspaceName = '';

    public function createWorkspace(CreateWorkspace $createWorkspace): void
    {
        $validated = $this->validate([
            'workspaceName' => ['required', 'string', 'max:255'],
        ]);

        $team = Auth::user()->currentTeam;

        abort_unless($team, 403);

        $workspace = $createWorkspace->handle($team, $validated['workspaceName']);

        Auth::user()->switchWorkspace($workspace);

        $this->reset('workspaceName');

        Flux::toast(variant: 'success', text: __('Workspace created.'));

        $this->redirectRoute('dashboard', navigate: true);
    }
}; ?>

<flux:modal name="create-workspace-switcher" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <form wire:submit="createWorkspace" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Create a new workspace') }}</flux:heading>
            <flux:subheading>{{ __('Give your workspace a name to get started.') }}</flux:subheading>
        </div>

        <flux:input wire:model="workspaceName" :label="__('Workspace name')" type="text" required autofocus data-test="switcher-create-workspace-name" />

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button variant="primary" type="submit" data-test="switcher-create-workspace-submit">
                {{ __('Create workspace') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
