<div class="flex flex-1 flex-col gap-4 overflow-auto px-4 py-4 xl:min-h-0" data-test="dashboard-agent-panel">
    @if ($selectedDashboardAgent->latestVersion)
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm dark:border-amber-300/20 dark:bg-amber-500/10">
            <div class="font-medium text-amber-950 dark:text-amber-100">{{ __('Current') }}: v{{ $selectedDashboardAgent->latestVersion->version }}</div>
            <div class="text-amber-700 dark:text-amber-200">
                {{ $selectedDashboardAgent->latestVersion->provider->label() }} / {{ $selectedDashboardAgent->latestVersion->model }}
            </div>
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 dark:border-white/10">
        <div class="border-b border-neutral-100 px-4 py-3 dark:border-white/5">
            <flux:heading size="sm">{{ __('Agent details') }}</flux:heading>
        </div>

        <form wire:submit="saveSelectedAgentDetails" class="space-y-4 p-4">
            <flux:input wire:model="selectedAgentName" :label="__('Name')" type="text" required />

            <div class="flex justify-end">
                <flux:button type="submit" variant="filled">{{ __('Save agent') }}</flux:button>
            </div>
        </form>
    </div>

    <div class="rounded-lg border border-neutral-200 dark:border-white/10">
        <div class="border-b border-neutral-100 px-4 py-3 dark:border-white/5">
            <flux:heading size="sm">{{ __('New version') }}</flux:heading>
        </div>

        <form wire:submit="saveSelectedAgentVersion" class="space-y-4 p-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="selectedAgentProvider" :label="__('Provider')" placeholder="{{ __('Select provider…') }}" required>
                    <x-provider-options />
                </flux:select>

                <flux:select wire:model="selectedAgentModel" :label="__('Model')" placeholder="{{ __('Select model…') }}" :disabled="!$selectedAgentProvider" required>
                    @foreach ($this->selectedAgentAvailableModels as $availableModel)
                        <flux:select.option :value="$availableModel">{{ $availableModel }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @if ($this->selectedAgentShowReasoningEffort)
                <flux:select wire:model="selectedAgentReasoningEffort" :label="__('Reasoning effort')" placeholder="{{ __('Select effort…') }}">
                    <x-reasoning-effort-options />
                </flux:select>
            @endif

            <flux:textarea wire:model="selectedAgentPrompt" :label="__('Prompt')" rows="7" :placeholder="__('System prompt…')" />

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary">{{ __('Save version') }}</flux:button>
            </div>
        </form>
    </div>

    <div class="rounded-lg border border-neutral-200 dark:border-white/10">
        <div class="border-b border-neutral-100 px-4 py-3 dark:border-white/5">
            <flux:heading size="sm">{{ __('Version history') }}</flux:heading>
        </div>

        @if ($selectedDashboardAgent->versions->isEmpty())
            <div class="px-4 py-6 text-center">
                <flux:text class="text-sm text-neutral-400 dark:text-neutral-600">{{ __('No versions yet.') }}</flux:text>
            </div>
        @else
            <div class="divide-y divide-neutral-100 dark:divide-white/5">
                @foreach ($selectedDashboardAgent->versions as $version)
                    <div class="px-4 py-3">
                        <div class="flex items-center justify-between gap-3">
                            <flux:badge color="zinc" size="sm">v{{ $version->version }}</flux:badge>
                            <flux:text class="text-xs text-neutral-400" :title="$version->created_at->timezone(Auth::user()->displayTimezone())->isoFormat('LLLL')">{{ $version->created_at->diffForHumans() }}</flux:text>
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
