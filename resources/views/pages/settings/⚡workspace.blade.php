<?php

use App\Enums\Provider;
use App\Models\ProviderKey;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::workspace'), Title('Workspace settings')] class extends Component
{
    public array $workspaceProviderKeys = [];

    public string $newKeyProvider = '';

    public string $newKeyValue = '';

    public function mount(): void
    {
        $this->loadKeys();
    }

    public function saveWorkspaceApiKey(): void
    {
        $workspace = Auth::user()->currentWorkspace;
        abort_unless($workspace, 403);

        $this->validate([
            'newKeyProvider' => ['required', Rule::enum(Provider::class)],
            'newKeyValue' => ['required', 'string', 'min:10'],
        ], [], [
            'newKeyProvider' => __('provider'),
            'newKeyValue' => __('API key'),
        ]);

        ProviderKey::updateOrCreate(
            ['workspace_id' => $workspace->id, 'provider' => $this->newKeyProvider],
            ['api_key' => $this->newKeyValue],
        );

        $this->reset('newKeyProvider', 'newKeyValue');
        $this->loadKeys();

        Flux::toast(variant: 'success', text: __('API key saved.'));
    }

    public function deleteWorkspaceApiKey(int $id): void
    {
        $workspace = Auth::user()->currentWorkspace;
        abort_unless($workspace, 403);

        ProviderKey::query()
            ->where('id', $id)
            ->where('workspace_id', $workspace->id)
            ->firstOrFail()
            ->delete();

        $this->loadKeys();

        Flux::toast(variant: 'success', text: __('API key removed.'));
    }

    private function loadKeys(): void
    {
        $workspace = Auth::user()->currentWorkspace;

        $this->workspaceProviderKeys = $workspace
            ? $workspace->providerKeys()->get()->map(fn ($key) => [
                'id' => $key->id,
                'provider' => $key->provider->value,
                'provider_label' => $key->provider->label(),
            ])->toArray()
            : [];
    }
}; ?>

<section class="flex w-full flex-1">
    <x-pages::settings.layout :heading="__('Workspace')" :subheading="__('API key overrides for the current workspace')">
        <div class="space-y-6">
            <div>
                <flux:heading>{{ __('API keys') }}</flux:heading>
                <flux:subheading>{{ __('Override team-level keys for this workspace. Workspace keys take priority.') }}</flux:subheading>
            </div>

            @if (count($workspaceProviderKeys) > 0)
                <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-900">
                    @foreach ($workspaceProviderKeys as $key)
                        <div class="flex items-center justify-between px-4 py-3" data-test="workspace-api-key-row">
                            <div class="flex items-center gap-3">
                                <flux:icon name="key" class="size-4 text-zinc-400" />
                                <div>
                                    <div class="text-sm font-medium">{{ $key['provider_label'] }}</div>
                                    <flux:text class="text-xs text-zinc-400">{{ __('••••••••••••••••') }}</flux:text>
                                </div>
                            </div>
                            <flux:button
                                wire:click="deleteWorkspaceApiKey({{ $key['id'] }})"
                                wire:confirm="{{ __('Remove this API key?') }}"
                                variant="ghost"
                                size="sm"
                                icon="trash"
                                class="text-zinc-400 hover:text-red-500"
                                data-test="workspace-api-key-delete"
                            />
                        </div>
                    @endforeach
                </div>
            @endif

            <form wire:submit="saveWorkspaceApiKey" class="flex items-end gap-3" data-test="workspace-api-key-form">
                <flux:select wire:model="newKeyProvider" :label="__('Provider')" class="w-40" data-test="workspace-api-key-provider">
                    <flux:select.option value="">{{ __('Select…') }}</flux:select.option>
                    @foreach (Provider::cases() as $provider)
                        <flux:select.option value="{{ $provider->value }}">{{ $provider->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="newKeyValue" :label="__('API key')" type="password" class="flex-1" placeholder="sk-..." data-test="workspace-api-key-value" />

                <flux:button type="submit" variant="primary" data-test="workspace-api-key-save">
                    {{ __('Save key') }}
                </flux:button>
            </form>
        </div>
    </x-pages::settings.layout>
</section>
