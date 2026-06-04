<?php

use App\Enums\Provider;
use App\Enums\ReasoningEffort;
use App\Models\Agent;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Agent')] class extends Component {
    public Agent $agent;

    public string $agentName = '';

    public string $provider = '';

    public string $model = '';

    public string $reasoningEffort = '';

    public string $prompt = '';

    public function mount(Agent $agent): void
    {
        abort_unless(
            Auth::user()->currentWorkspace?->id === $agent->workspace_id,
            403
        );

        $latest = $agent->latestVersion;

        $this->agentName = $agent->name;

        if ($latest) {
            $this->provider = $latest->provider->value;
            $this->model = $latest->model;
            $this->reasoningEffort = $latest->reasoning_effort?->value ?? '';
            $this->prompt = $latest->prompt ?? '';
        }
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

    public function saveDetails(): void
    {
        $validated = $this->validate([
            'agentName' => ['required', 'string', 'max:255'],
        ]);

        $this->agent->update([
            'name' => $validated['agentName'],
        ]);

        Flux::toast(variant: 'success', text: __('Agent saved.'));
    }

    public function saveVersion(): void
    {
        $validated = $this->validate([
            'provider' => ['required', 'string', 'in:'.implode(',', array_column(Provider::cases(), 'value'))],
            'model' => ['required', 'string', 'max:255'],
            'reasoningEffort' => ['nullable', 'string', 'in:'.implode(',', array_column(ReasoningEffort::cases(), 'value'))],
            'prompt' => ['nullable', 'string'],
        ]);

        $this->agent->versions()->create([
            'provider' => $validated['provider'],
            'model' => $validated['model'],
            'reasoning_effort' => $validated['reasoningEffort'] ?: null,
            'prompt' => $validated['prompt'] ?: null,
        ]);

        Flux::toast(variant: 'success', text: __('Version saved.'));
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    {{-- Header --}}
    <div class="flex flex-col gap-3 border-b border-neutral-100 pb-4 dark:border-white/5">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('agents')" wire:navigate>{{ __('Agents') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $agent->name }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading size="xl">{{ $agent->name }}</flux:heading>
                <flux:subheading>{{ __('Configure the agent identity, then save prompt/model snapshots as versions.') }}</flux:subheading>
            </div>

            @if ($agent->latestVersion)
                <div class="rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5">
                    <div class="font-medium text-neutral-700 dark:text-neutral-300">{{ __('Latest version') }}: v{{ $agent->latestVersion->version }}</div>
                    <div class="text-neutral-500 dark:text-neutral-400">
                        {{ $agent->latestVersion->provider->label() }} / {{ $agent->latestVersion->model }}
                    </div>
                </div>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1.7fr)_22rem]">
        {{-- Version form --}}
        <div class="order-2 xl:order-1">
            <div class="rounded-lg border border-neutral-200 dark:border-white/10">
                <div class="border-b border-neutral-100 px-4 py-3 dark:border-white/5">
                    <flux:heading size="sm">{{ __('New version') }}</flux:heading>
                    <flux:subheading>{{ __('Saving creates an immutable snapshot.') }}</flux:subheading>
                </div>

                <form wire:submit="saveVersion" class="space-y-4 p-4">
                    <div class="grid grid-cols-2 gap-4">
                        <flux:select wire:model.live="provider" :label="__('Provider')" placeholder="{{ __('Select provider…') }}" required>
                            @foreach (Provider::cases() as $p)
                                <flux:select.option :value="$p->value">{{ $p->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model="model" :label="__('Model')" placeholder="{{ __('Select model…') }}" :disabled="!$provider" required>
                            @foreach ($this->availableModels as $m)
                                <flux:select.option :value="$m">{{ $m }}</flux:select.option>
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

                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary">{{ __('Save version') }}</flux:button>
                    </div>
                </form>
            </div>
        </div>

        <div class="order-1 xl:order-2 xl:sticky xl:top-6">
            <div class="flex flex-col gap-6">
                <div class="rounded-lg border border-neutral-200 dark:border-white/10">
                    <div class="border-b border-neutral-100 px-4 py-3 dark:border-white/5">
                        <flux:heading size="sm">{{ __('Agent details') }}</flux:heading>
                    </div>

                    <form wire:submit="saveDetails" class="space-y-4 p-4">
                        <flux:input wire:model="agentName" :label="__('Name')" type="text" required />

                        <div class="flex justify-end">
                            <flux:button type="submit" variant="primary">{{ __('Save agent') }}</flux:button>
                        </div>
                    </form>
                </div>

                {{-- Version history --}}
                <div class="rounded-lg border border-neutral-200 dark:border-white/10">
                    <div class="border-b border-neutral-100 px-4 py-3 dark:border-white/5">
                        <flux:heading size="sm">{{ __('Version history') }}</flux:heading>
                    </div>

                    @php $versions = $agent->versions()->orderByDesc('version')->get(); @endphp

                    @if ($versions->isEmpty())
                        <div class="px-4 py-6 text-center">
                            <flux:text class="text-sm text-neutral-400 dark:text-neutral-600">{{ __('No versions yet.') }}</flux:text>
                        </div>
                    @else
                        <div class="divide-y divide-neutral-100 dark:divide-white/5">
                            @foreach ($versions as $version)
                                <div class="px-4 py-3">
                                    <div class="flex items-center justify-between">
                                        <flux:badge color="zinc" size="sm">v{{ $version->version }}</flux:badge>
                                        <flux:text class="text-xs text-neutral-400">{{ $version->created_at->diffForHumans() }}</flux:text>
                                    </div>
                                    <div class="mt-1.5 space-y-0.5">
                                        <flux:text class="text-xs text-neutral-600 dark:text-neutral-400">
                                            {{ $version->provider->label() }} / {{ $version->model }}
                                        </flux:text>
                                        @if ($version->reasoning_effort)
                                            <flux:text class="text-xs text-neutral-500">
                                                {{ __('Reasoning:') }} {{ $version->reasoning_effort->label() }}
                                            </flux:text>
                                        @endif
                                        @if ($version->prompt)
                                            <flux:text class="line-clamp-2 text-xs text-neutral-400">{{ $version->prompt }}</flux:text>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
