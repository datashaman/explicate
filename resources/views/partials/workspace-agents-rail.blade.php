@props([
    'agents',
    'createModal',
    'assignedAgentIds' => [],
    'assignAction' => null,
    'unassignAction' => null,
    'panelId' => null,
    'asideClass' => null,
    'containerClass' => null,
    'sticky' => true,
])

@php
    $assignedAgentIds = collect($assignedAgentIds)->map(fn ($id) => (int) $id)->all();
    $hasAssignmentContext = (bool) ($assignAction && $unassignAction);
    $assignedAgents = $hasAssignmentContext
        ? $agents->filter(fn ($agent) => in_array($agent->id, $assignedAgentIds, true))
        : collect();
    $availableAgents = $hasAssignmentContext
        ? $agents->reject(fn ($agent) => in_array($agent->id, $assignedAgentIds, true))
        : $agents;
@endphp

<aside @if ($panelId) id="{{ $panelId }}" @endif @class([$sticky ? 'xl:sticky xl:top-6' : null, $asideClass])>
    <div @class([
        'flex flex-col overflow-hidden rounded-xl border border-neutral-300 bg-white shadow-sm shadow-black/[0.04] xl:h-full xl:min-h-[24rem] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none',
        $containerClass,
    ])>
        <div class="flex items-center justify-between gap-3 border-b border-neutral-300 bg-amber-50 px-4 py-3 dark:border-white/10 dark:bg-amber-500/10">
            <flux:heading size="sm">{{ __('Agents') }}</flux:heading>
            <flux:modal.trigger :name="$createModal">
                <flux:button icon="plus" size="xs">{{ __('New agent') }}</flux:button>
            </flux:modal.trigger>
        </div>

        @if ($agents->isEmpty())
            <div class="bg-white px-4 py-6 text-center xl:flex xl:flex-1 xl:items-start dark:bg-zinc-900/20">
                <flux:text class="text-sm text-neutral-400 dark:text-neutral-600">{{ __('No agents in this workspace.') }}</flux:text>
            </div>
        @else
            <div class="flex flex-col bg-white xl:flex-1 xl:overflow-auto dark:bg-zinc-900/20">
                @foreach ([['label' => __('Assigned'), 'agents' => $assignedAgents, 'assigned' => true], ['label' => __('Available'), 'agents' => $availableAgents, 'assigned' => false]] as $section)
                    @continue($section['agents']->isEmpty())

                    @if ($hasAssignmentContext && ($assignedAgents->isNotEmpty() || $section['assigned']))
                        <div @class([
                            'border-t border-neutral-200 px-4 pb-1 pt-3 first:border-t-0 dark:border-white/5',
                            'bg-amber-50/60 dark:bg-amber-500/10' => $section['assigned'],
                        ])>
                            <flux:text class="text-xs font-medium uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ $section['label'] }}</flux:text>
                        </div>
                    @endif

                    <div @class([
                        'divide-y divide-neutral-200 dark:divide-white/5',
                        'bg-amber-50/60 dark:bg-amber-500/10' => $section['assigned'],
                    ])>
                        @foreach ($section['agents'] as $agent)
                            @php $isAssigned = $section['assigned']; @endphp

                            <div
                                wire:key="workspace-agent-row-{{ $agent->id }}-{{ $isAssigned ? 'assigned' : 'available' }}"
                                data-test="workspace-agent-row-{{ $agent->slug }}"
                                @class([
                                    'flex items-center gap-3 px-4 py-3 hover:bg-neutral-100 dark:hover:bg-white/5',
                                    'border-l-2 border-amber-400 bg-amber-50/80 hover:bg-amber-100/70 dark:bg-amber-500/10 dark:hover:bg-amber-500/15' => $isAssigned,
                                ])
                            >
                                <a href="{{ route('agents.show', ['agent' => $agent->slug]) }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-3">
                                    <div @class([
                                        'workspace-agent-icon mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full',
                                        'bg-amber-100 text-amber-700 ring-1 ring-amber-300 dark:bg-amber-400/15 dark:text-amber-200 dark:ring-amber-300/30' => $isAssigned,
                                        'bg-neutral-100 text-neutral-500 dark:bg-white/10 dark:text-neutral-300' => ! $isAssigned,
                                    ])>
                                        <flux:icon name="cpu-chip" :variant="$isAssigned ? 'solid' : 'outline'" class="size-4" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div @class([
                                            'truncate text-sm font-medium',
                                            'text-amber-950 dark:text-amber-100' => $isAssigned,
                                            'text-neutral-700 dark:text-neutral-300' => ! $isAssigned,
                                        ])>{{ $agent->name }}</div>
                                    </div>
                                </a>

                                @if ($assignAction && $unassignAction)
                                    <div class="shrink-0">
                                    @if ($isAssigned)
                                        <flux:button
                                            wire:key="workspace-agent-detach-{{ $agent->id }}"
                                            wire:click="{{ $unassignAction }}({{ $agent->id }})"
                                            wire:confirm="{{ __('Detach this agent from the topic?') }}"
                                            variant="subtle"
                                            size="xs"
                                            icon="minus"
                                            tooltip="{{ __('Detach from topic') }}"
                                            class="aspect-square px-0"
                                        />
                                    @else
                                        <flux:button
                                            wire:key="workspace-agent-attach-{{ $agent->id }}"
                                            wire:click="{{ $assignAction }}({{ $agent->id }})"
                                            variant="subtle"
                                            size="xs"
                                            icon="plus"
                                            tooltip="{{ __('Attach to topic') }}"
                                            class="aspect-square px-0"
                                        />
                                    @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</aside>
