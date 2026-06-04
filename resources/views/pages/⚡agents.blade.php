<?php

use App\Enums\Provider;
use App\Enums\ReasoningEffort;
use App\Models\Agent;
use App\Models\Workspace;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Agents')] class extends Component {
    public string $agentName = '';

    public string $provider = '';

    public string $model = '';

    public string $reasoningEffort = '';

    public string $prompt = '';

    public function workspace(): ?Workspace
    {
        return Auth::user()->currentWorkspace;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Agent>
     */
    public function agents(): \Illuminate\Database\Eloquent\Collection
    {
        $workspace = $this->workspace();

        return $workspace
            ? $workspace->agents()->get()
            : Agent::query()->whereNull('id')->get();
    }

    /** @return list<string> */
    #[Computed]
    public function availableModels(): array
    {
        if (! $this->provider) {
            return [];
        }

        $provider = Provider::tryFrom($this->provider);

        return $provider ? $provider->models() : [];
    }

    #[Computed]
    public function showReasoningEffort(): bool
    {
        if (! $this->provider) {
            return false;
        }

        $provider = Provider::tryFrom($this->provider);

        return $provider?->supportsReasoningEffort() ?? false;
    }

    public function updatedProvider(): void
    {
        $this->model = '';
        $this->reasoningEffort = '';
    }

    public function createAgent(): void
    {
        $workspace = $this->workspace();

        abort_unless($workspace, 403);

        $validated = $this->validate([
            'agentName' => ['required', 'string', 'max:255'],
            'provider' => ['required', 'string', 'in:'.implode(',', array_column(Provider::cases(), 'value'))],
            'model' => ['required', 'string', 'max:255'],
            'reasoningEffort' => ['nullable', 'string', 'in:'.implode(',', array_column(ReasoningEffort::cases(), 'value'))],
            'prompt' => ['nullable', 'string'],
        ]);

        $agent = $workspace->agents()->create(['name' => $validated['agentName']]);

        $agent->versions()->create([
            'provider' => $validated['provider'],
            'model' => $validated['model'],
            'reasoning_effort' => $validated['reasoningEffort'] ?: null,
            'prompt' => $validated['prompt'] ?: null,
        ]);

        $this->reset('agentName', 'provider', 'model', 'reasoningEffort', 'prompt');

        Flux::modal('new-agent')->close();

        Flux::toast(variant: 'success', text: __('Agent created.'));
    }

    public function deleteAgent(int $agentId): void
    {
        $workspace = $this->workspace();

        abort_unless($workspace, 403);

        $workspace->agents()->findOrFail($agentId)->delete();

        Flux::toast(variant: 'success', text: __('Agent deleted.'));
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-4">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">{{ __('Agents') }}</flux:heading>

        @if ($this->workspace())
            <flux:modal.trigger name="new-agent">
                <flux:button icon="plus" size="sm">{{ __('New agent') }}</flux:button>
            </flux:modal.trigger>
        @endif
    </div>

    @if ($this->workspace())
        @if ($this->agents()->isNotEmpty())
            <div class="divide-y divide-neutral-100 dark:divide-white/5 rounded-lg border border-neutral-200 dark:border-white/10">
                @foreach ($this->agents() as $agent)
                    <div class="flex items-center gap-3 px-4 py-3">
                        <flux:icon name="cpu-chip" class="size-5 shrink-0 text-neutral-400" />
                        <a href="{{ route('agents.show', ['agent' => $agent->slug]) }}" wire:navigate class="flex-1 text-sm text-neutral-700 hover:underline dark:text-neutral-300">
                            {{ $agent->name }}
                        </a>
                        <flux:button
                            wire:click="deleteAgent({{ $agent->id }})"
                            wire:confirm="{{ __('Delete this agent?') }}"
                            icon="trash"
                            variant="ghost"
                            size="xs"
                            class="text-neutral-400 hover:text-red-500"
                        />
                    </div>
                @endforeach
            </div>
        @else
            <div class="flex flex-1 items-center justify-center">
                <flux:text class="text-sm text-neutral-400 dark:text-neutral-600">{{ __('No agents') }}</flux:text>
            </div>
        @endif

        <flux:modal name="new-agent" :show="$errors->isNotEmpty()" focusable class="max-w-sm">
            <form wire:submit="createAgent" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('New agent') }}</flux:heading>
                    <flux:subheading>{{ __('Set up your agent and create its first version.') }}</flux:subheading>
                </div>

                <flux:input wire:model="agentName" :label="__('Name')" type="text" required autofocus />

                <div class="grid grid-cols-2 gap-4">
                    <flux:select wire:model.live="provider" :label="__('Provider')" placeholder="{{ __('Select provider…') }}" required>
                        @foreach (Provider::cases() as $providerOption)
                            <flux:select.option :value="$providerOption->value">{{ $providerOption->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="model" :label="__('Model')" placeholder="{{ __('Select model…') }}" :disabled="!$provider" required>
                        @foreach ($this->availableModels as $availableModel)
                            <flux:select.option :value="$availableModel">{{ $availableModel }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                @if ($this->showReasoningEffort)
                    <flux:select wire:model="reasoningEffort" :label="__('Reasoning effort')" placeholder="{{ __('Select effort…') }}">
                        <flux:select.option value="">{{ __('None') }}</flux:select.option>
                        @foreach (ReasoningEffort::cases() as $effort)
                            <flux:select.option :value="$effort->value">{{ $effort->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:textarea wire:model="prompt" :label="__('Prompt')" rows="8" :placeholder="__('System prompt…')" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit">{{ __('Create') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @else
        <div class="flex flex-1 flex-col items-center justify-center gap-4">
            <div class="text-center">
                <flux:heading>{{ __('No workspace selected') }}</flux:heading>
                <flux:subheading>{{ __('Select or create a workspace to get started.') }}</flux:subheading>
            </div>
        </div>
    @endif
</div>
