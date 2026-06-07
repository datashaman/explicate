@props([
    'agents',
    'createModal',
    'selectAction' => null,
    'panelId' => null,
    'asideClass' => null,
    'containerClass' => null,
    'sticky' => true,
])

<aside @if ($panelId) id="{{ $panelId }}" @endif @class([$sticky ? 'xl:sticky xl:top-6' : null, $asideClass])>
    <div @class([
        'flex flex-col overflow-hidden rounded-lg border border-neutral-300 bg-white shadow-sm shadow-black/[0.04] xl:h-full xl:min-h-[24rem] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none',
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
                <div class="divide-y divide-neutral-200 dark:divide-white/5">
                        @foreach ($agents as $agent)
                            <div
                                wire:key="workspace-agent-row-{{ $agent->id }}"
                                data-test="workspace-agent-row-{{ $agent->slug }}"
                                class="flex items-center gap-3 px-4 py-3 hover:bg-neutral-100 dark:hover:bg-white/5"
                            >
                                @if ($selectAction)
                                    <button type="button" wire:click="{{ $selectAction }}('{{ $agent->slug }}')" class="flex min-w-0 flex-1 cursor-pointer items-center gap-3 text-left">
                                @else
                                    <a href="{{ route('agents.show', ['agent' => $agent->slug]) }}" wire:navigate class="flex min-w-0 flex-1 cursor-pointer items-center gap-3">
                                @endif
                                    <div @class([
                                        'workspace-agent-icon mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full',
                                        'bg-neutral-100 text-neutral-500 dark:bg-white/10 dark:text-neutral-300',
                                    ])>
                                        <flux:icon name="cpu-chip" variant="outline" class="size-4" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ $agent->name }}</div>
                                    </div>
                                @if ($selectAction)
                                    </button>
                                @else
                                    </a>
                                @endif

                            </div>
                        @endforeach
                </div>
            </div>
        @endif
    </div>
</aside>
