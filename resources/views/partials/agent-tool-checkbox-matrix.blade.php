<div class="space-y-3" data-test="{{ $dataTest ?? 'agent-tool-matrix' }}">
    <div class="space-y-1">
        <flux:label>{{ $label ?? __('Allowed tools') }}</flux:label>
        <flux:text class="text-xs text-neutral-500 dark:text-neutral-400">
            {{ $description ?? __('Choose which MCP tools this agent version may call while working.') }}
        </flux:text>
    </div>

    <flux:checkbox.group wire:model="{{ $model }}">
        <div class="grid grid-cols-1 gap-3 xl:grid-cols-2">
            @foreach ($groups as $groupName => $group)
                <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 dark:border-white/10 dark:bg-zinc-950/40" wire:key="tool-group-{{ \Illuminate\Support\Str::slug($groupName) }}">
                    <div class="mb-3">
                        <flux:heading size="sm">{{ $groupName }}</flux:heading>
                        <flux:text class="text-xs text-neutral-500 dark:text-neutral-400">{{ $group['description'] }}</flux:text>
                    </div>

                    <div class="flex flex-wrap items-start gap-2">
                        @foreach ($group['tools'] as $tool)
                            <div class="w-full sm:w-[calc(50%-0.25rem)]" wire:key="tool-option-{{ $tool['name'] }}">
                                <flux:checkbox
                                    :value="$tool['name']"
                                    :label="$tool['name']"
                                    :description="$tool['description']"
                                />
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </flux:checkbox.group>

    <flux:error :name="$model" />
</div>
