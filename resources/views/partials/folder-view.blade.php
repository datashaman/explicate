<div x-data="{
    view: localStorage.getItem('folder-view-mode') || 'icons',
    setView(v) { this.view = v; localStorage.setItem('folder-view-mode', v); },
    editing: false,
    startEdit() { this.editing = true; this.$nextTick(() => this.$refs.nameInput?.focus()); },
    cancelEdit() { this.editing = false; @isset($editNameModel) $wire.set('{{ $editNameModel }}', '{{ addslashes(end($breadcrumbs)['label']) }}'); @endisset },
}"
@isset($editNameDispatch) x-on:{{ $editNameDispatch }}.window="editing = false" @endisset
>
    {{-- Toolbar --}}
    <div class="flex items-center justify-between gap-4">
        {{-- Breadcrumbs / inline name editor --}}
        <div class="flex min-w-0 items-center gap-2">
            @isset($editNameModel)
                {{-- All but last breadcrumb --}}
                @if (count($breadcrumbs) > 1)
                    <flux:breadcrumbs>
                        @foreach (array_slice($breadcrumbs, 0, -1) as $crumb)
                            @if (isset($crumb['href']))
                                <flux:breadcrumbs.item :href="$crumb['href']" wire:navigate>{{ $crumb['label'] }}</flux:breadcrumbs.item>
                            @else
                                <flux:breadcrumbs.item>{{ $crumb['label'] }}</flux:breadcrumbs.item>
                            @endif
                        @endforeach
                    </flux:breadcrumbs>
                    <span class="text-neutral-300 dark:text-neutral-600">/</span>
                @endif

                {{-- Last breadcrumb: view or edit --}}
                <span x-show="!editing" class="group flex items-center gap-1">
                    <span class="text-sm text-neutral-700 dark:text-neutral-300">{{ end($breadcrumbs)['label'] }}</span>
                    <flux:button x-on:click="startEdit" icon="pencil" variant="ghost" size="xs"
                                 class="opacity-0 transition-opacity group-hover:opacity-100" />
                </span>

                <form x-show="editing" x-cloak wire:submit="{{ $editNameAction }}" class="flex items-center gap-2">
                    <flux:input x-ref="nameInput" wire:model="{{ $editNameModel }}" size="sm" required />
                    <flux:button type="submit" size="sm" variant="primary">{{ __('Save') }}</flux:button>
                    <flux:button type="button" size="sm" x-on:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                </form>
            @else
                <flux:breadcrumbs>
                    @foreach ($breadcrumbs as $crumb)
                        @if (isset($crumb['href']))
                            <flux:breadcrumbs.item :href="$crumb['href']" wire:navigate>{{ $crumb['label'] }}</flux:breadcrumbs.item>
                        @else
                            <flux:breadcrumbs.item>{{ $crumb['label'] }}</flux:breadcrumbs.item>
                        @endif
                    @endforeach
                </flux:breadcrumbs>
            @endisset
        </div>

        <div class="flex shrink-0 items-center gap-3">
            @isset($showArchivedModel)
                <div x-data="{
                    init() {
                        const stored = localStorage.getItem('show-archived');
                        if (stored !== null) $wire.set('{{ $showArchivedModel }}', stored === 'true');
                        $watch('$wire.{{ $showArchivedModel }}', v => localStorage.setItem('show-archived', v));
                    }
                }">
                    <flux:checkbox wire:model.live="{{ $showArchivedModel }}" :label="__('Show archived')" />
                </div>
            @endisset

            <div class="flex items-center rounded-lg border border-neutral-200 dark:border-white/10">
                <button @click="setView('icons')" title="{{ __('Icon view') }}"
                        :class="view === 'icons' ? 'bg-neutral-100 dark:bg-white/10 text-neutral-900 dark:text-white' : 'text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300'"
                        class="rounded-l-lg p-1.5 transition-colors">
                    <flux:icon name="squares-2x2" variant="mini" class="size-4" />
                </button>
                <div class="w-px self-stretch bg-neutral-200 dark:bg-white/10"></div>
                <button @click="setView('list')" title="{{ __('List view') }}"
                        :class="view === 'list' ? 'bg-neutral-100 dark:bg-white/10 text-neutral-900 dark:text-white' : 'text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300'"
                        class="rounded-r-lg p-1.5 transition-colors">
                    <flux:icon name="list-bullet" variant="mini" class="size-4" />
                </button>
            </div>

            <flux:modal.trigger :name="$createModal">
                <flux:button icon="plus" size="sm">{{ $createLabel }}</flux:button>
            </flux:modal.trigger>
        </div>
    </div>

    {{-- Content --}}
    <div class="mt-4 overflow-auto">
        @if ($items->isNotEmpty())
            <template x-if="view === 'icons'">
                <div class="flex flex-wrap justify-start gap-6 content-start">
                    @foreach ($items as $item)
                        <a href="{{ $item['href'] }}" wire:navigate
                           class="group flex w-24 flex-col items-center gap-1 rounded-lg p-2 text-center hover:bg-neutral-100 dark:hover:bg-white/5">
                            <flux:icon :name="$icon" class="{{ $iconClass }} drop-shadow-sm" />
                            <span class="break-normal text-xs text-neutral-700 dark:text-neutral-300">{{ $item['name'] }}</span>
                            @if (!empty($item['counts']))
                                <div class="flex flex-wrap justify-center gap-1">
                                    @foreach ($item['counts'] as $count)
                                        <flux:badge :color="$count['color']" size="sm" :title="$count['label']">{{ $count['value'] }}</flux:badge>
                                    @endforeach
                                </div>
                            @endif
                            @if (!empty($item['badge']))
                                <flux:badge :color="$item['badge']['color']" size="sm">{{ $item['badge']['label'] }}</flux:badge>
                            @endif
                        </a>
                    @endforeach
                </div>
            </template>

            <template x-if="view === 'list'">
                <div class="divide-y divide-neutral-100 dark:divide-white/5">
                    @foreach ($items as $item)
                        <a href="{{ $item['href'] }}" wire:navigate
                           class="flex items-center gap-3 rounded-lg px-2 py-2 hover:bg-neutral-100 dark:hover:bg-white/5">
                            <flux:icon :name="$icon" class="{{ $iconClass }} shrink-0" />
                            <span class="flex-1 text-sm text-neutral-700 dark:text-neutral-300">{{ $item['name'] }}</span>
                            @if (!empty($item['counts']))
                                <div class="flex items-center gap-1">
                                    @foreach ($item['counts'] as $count)
                                        <flux:badge :color="$count['color']" size="sm" :title="$count['label']">{{ $count['value'] }}</flux:badge>
                                    @endforeach
                                </div>
                            @endif
                            @if (!empty($item['badge']))
                                <flux:badge :color="$item['badge']['color']" size="sm">{{ $item['badge']['label'] }}</flux:badge>
                            @endif
                        </a>
                    @endforeach
                </div>
            </template>
        @else
            <div class="flex h-full items-start justify-start pt-16">
                <flux:text class="text-sm text-neutral-400 dark:text-neutral-600">{{ $emptyText }}</flux:text>
            </div>
        @endif
    </div>
</div>
