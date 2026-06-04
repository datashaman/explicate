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
    <div class="flex items-center gap-2">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('agents')" wire:navigate>{{ __('Agents') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $agent->name }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Version form --}}
        <div class="lg:col-span-2">
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

        {{-- Version history --}}
        <div>
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
