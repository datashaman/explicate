<div x-data="{
    view: localStorage.getItem('folder-view-mode') || 'icons',
    setView(v) { this.view = v; localStorage.setItem('folder-view-mode', v); },
    listSortKey: '{{ $listDefaultSort ?? 'name' }}',
    listSortDirection: '{{ $listDefaultSortDirection ?? 'asc' }}',
    sortList(key) {
        if (this.listSortKey === key) {
            this.listSortDirection = this.listSortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            this.listSortKey = key;
            this.listSortDirection = ['sent', 'saved'].includes(key) ? 'desc' : 'asc';
        }

        this.$nextTick(() => this.sortListRows());
    },
    sortListRows() {
        const rows = Array.from(this.$refs.listRows?.children || []);

        rows.sort((a, b) => {
            const aValue = this.listSortValue(a, this.listSortKey);
            const bValue = this.listSortValue(b, this.listSortKey);
            const comparison = aValue.localeCompare(bValue, undefined, { numeric: true, sensitivity: 'base' });

            if (comparison !== 0) {
                return this.listSortDirection === 'asc' ? comparison : -comparison;
            }

            return Number(a.dataset.sortIndex) - Number(b.dataset.sortIndex);
        });

        rows.forEach(row => this.$refs.listRows.appendChild(row));
    },
    listSortValue(row, key) {
        return row.dataset[`sort${key.charAt(0).toUpperCase()}${key.slice(1)}`] || '';
    },
    controlsOpen: false,
    editing: false,
    startEdit() { this.editing = true; this.$nextTick(() => this.$refs.nameInput?.focus()); },
    cancelEdit() { this.editing = false; @isset($editNameModel) $wire.set('{{ $editNameModel }}', '{{ addslashes(end($breadcrumbs)['label']) }}'); @endisset },
}"
@isset($editNameDispatch) x-on:{{ $editNameDispatch }}.window="editing = false" @endisset
@class([$rootClass ?? null])
>
    @php
        $splitLastBreadcrumb = $splitLastBreadcrumb ?? false;
        $leadingBreadcrumbs = $splitLastBreadcrumb && count($breadcrumbs) > 1 ? array_slice($breadcrumbs, 0, -1) : $breadcrumbs;
        $lastBreadcrumb = $splitLastBreadcrumb && count($breadcrumbs) > 1 ? end($breadcrumbs) : null;
        $titleLabel = $titleLabel ?? null;
        $toolbarClass = $toolbarClass ?? null;
        $contentClass = $contentClass ?? 'mt-4 overflow-auto';
        $listIconClass = $listIconClass ?? str($iconClass)->replaceMatches('/\bsize-\S+/', 'size-5')->toString();
        $listColumns = $listColumns ?? [];
    @endphp

    {{-- Toolbar --}}
    <div @class(['flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between', $toolbarClass])>
        @if ($titleLabel)
            <div class="flex w-full min-w-0 items-center justify-between gap-3">
                <flux:heading size="sm" data-test="folder-title">{{ $titleLabel }}</flux:heading>

                <div class="flex shrink-0 items-center gap-2 md:hidden">
                    <flux:button
                        x-on:click="controlsOpen = !controlsOpen"
                        x-bind:aria-expanded="controlsOpen ? 'true' : 'false'"
                        icon="cog-6-tooth"
                        variant="filled"
                        size="xs"
                        class="aspect-square px-0"
                        data-test="folder-controls-toggle"
                    />

                    @isset($createHref)
                        <flux:button :href="$createHref" wire:navigate icon="plus" size="xs" data-test="{{ isset($createTest) ? $createTest.'-mobile' : 'folder-create-button-mobile' }}">{{ $createLabel }}</flux:button>
                    @else
                        <flux:modal.trigger :name="$createModal">
                            <flux:button icon="plus" size="xs">{{ $createLabel }}</flux:button>
                        </flux:modal.trigger>
                    @endisset
                </div>

                <div class="hidden shrink-0 items-center gap-3 md:flex">
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

                    <div class="flex items-center rounded-md border border-neutral-200 dark:border-white/10">
                        <button @click="setView('icons')" title="{{ __('Icon view') }}"
                                :class="view === 'icons' ? 'bg-neutral-100 dark:bg-white/10 text-neutral-900 dark:text-white' : 'text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300'"
                                class="rounded-l-md p-1 transition-colors">
                            <flux:icon name="squares-2x2" variant="mini" class="size-3.5" />
                        </button>
                        <div class="w-px self-stretch bg-neutral-200 dark:bg-white/10"></div>
                        <button @click="setView('list')" title="{{ __('List view') }}"
                                :class="view === 'list' ? 'bg-neutral-100 dark:bg-white/10 text-neutral-900 dark:text-white' : 'text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300'"
                                class="rounded-r-md p-1 transition-colors">
                            <flux:icon name="list-bullet" variant="mini" class="size-3.5" />
                        </button>
                    </div>

                    @isset($createHref)
                        <flux:button :href="$createHref" wire:navigate icon="plus" size="xs" data-test="{{ isset($createTest) ? $createTest.'-desktop' : 'folder-create-button-desktop' }}">{{ $createLabel }}</flux:button>
                    @else
                        <flux:modal.trigger :name="$createModal">
                            <flux:button icon="plus" size="xs">{{ $createLabel }}</flux:button>
                        </flux:modal.trigger>
                    @endisset
                </div>
            </div>

            <div
                x-show="controlsOpen"
                x-cloak
                x-transition.opacity.duration.150ms
                class="flex w-full flex-wrap items-center justify-between gap-2 md:hidden"
                data-test="folder-controls-drawer"
            >
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

                <div class="flex items-center rounded-md border border-neutral-200 dark:border-white/10">
                    <button @click="setView('icons')" title="{{ __('Icon view') }}"
                            :class="view === 'icons' ? 'bg-neutral-100 dark:bg-white/10 text-neutral-900 dark:text-white' : 'text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300'"
                            class="rounded-l-md p-1 transition-colors">
                        <flux:icon name="squares-2x2" variant="mini" class="size-3.5" />
                    </button>
                    <div class="w-px self-stretch bg-neutral-200 dark:bg-white/10"></div>
                    <button @click="setView('list')" title="{{ __('List view') }}"
                            :class="view === 'list' ? 'bg-neutral-100 dark:bg-white/10 text-neutral-900 dark:text-white' : 'text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300'"
                            class="rounded-r-md p-1 transition-colors">
                        <flux:icon name="list-bullet" variant="mini" class="size-3.5" />
                    </button>
                </div>
            </div>
        @else
            {{-- Breadcrumbs / inline name editor --}}
            <div class="flex min-w-0 items-center gap-2">
                @if (isset($editNameModel))
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
                @if ($splitLastBreadcrumb && $lastBreadcrumb)
                    <flux:breadcrumbs>
                        @foreach ($leadingBreadcrumbs as $crumb)
                            @if (isset($crumb['href']))
                                <flux:breadcrumbs.item :href="$crumb['href']" wire:navigate>{{ $crumb['label'] }}</flux:breadcrumbs.item>
                            @else
                                <flux:breadcrumbs.item>{{ $crumb['label'] }}</flux:breadcrumbs.item>
                            @endif
                        @endforeach
                    </flux:breadcrumbs>
                    <span class="text-neutral-300 dark:text-neutral-600">/</span>
                    <span class="text-sm text-neutral-700 dark:text-neutral-300">{{ $lastBreadcrumb['label'] }}</span>
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
                @endif
                @endif
            </div>

            <div class="flex w-full flex-wrap items-center justify-between gap-2 sm:w-auto sm:shrink-0 sm:flex-nowrap sm:justify-end sm:gap-3">
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

                <div class="flex items-center rounded-md border border-neutral-200 dark:border-white/10">
                    <button @click="setView('icons')" title="{{ __('Icon view') }}"
                            :class="view === 'icons' ? 'bg-neutral-100 dark:bg-white/10 text-neutral-900 dark:text-white' : 'text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300'"
                            class="rounded-l-md p-1 transition-colors">
                        <flux:icon name="squares-2x2" variant="mini" class="size-3.5" />
                    </button>
                    <div class="w-px self-stretch bg-neutral-200 dark:bg-white/10"></div>
                    <button @click="setView('list')" title="{{ __('List view') }}"
                            :class="view === 'list' ? 'bg-neutral-100 dark:bg-white/10 text-neutral-900 dark:text-white' : 'text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300'"
                            class="rounded-r-md p-1 transition-colors">
                        <flux:icon name="list-bullet" variant="mini" class="size-3.5" />
                    </button>
                </div>

                @isset($secondaryCreateHref)
                    <flux:button :href="$secondaryCreateHref" wire:navigate icon="plus" size="sm">{{ $secondaryCreateLabel }}</flux:button>
                @else
                    @isset($secondaryCreateModal)
                        <flux:modal.trigger :name="$secondaryCreateModal">
                            <flux:button icon="plus" size="sm">{{ $secondaryCreateLabel }}</flux:button>
                        </flux:modal.trigger>
                    @endisset
                @endisset

                @isset($createHref)
                    <flux:button :href="$createHref" wire:navigate icon="plus" size="sm">{{ $createLabel }}</flux:button>
                @else
                    <flux:modal.trigger :name="$createModal">
                        <flux:button icon="plus" size="sm">{{ $createLabel }}</flux:button>
                    </flux:modal.trigger>
                @endisset
            </div>
        @endif
    </div>

    {{-- Content --}}
    <div @class([$contentClass])>
        @if ($items->isNotEmpty())
            @php
                $hasListBadges = $items->contains(fn ($item) => !empty($item['badge']));
            @endphp

            <template x-if="view === 'icons'">
                <div class="flex flex-wrap content-start justify-start gap-3">
                    @foreach ($items as $item)
                        @php $itemKey = md5($item['href']); @endphp
                        <a href="{{ $item['href'] }}" wire:navigate
                           wire:key="folder-icon-{{ $itemKey }}"
                           class="group flex h-36 w-28 flex-col items-center gap-1 rounded-lg p-2 text-center hover:bg-neutral-100 dark:hover:bg-white/5">
                            <span class="flex h-14 items-center justify-center">
                                <flux:icon :name="$icon" class="{{ $iconClass }} drop-shadow-sm" />
                            </span>
                            <span class="line-clamp-2 min-h-8 w-full text-xs leading-4 text-neutral-700 dark:text-neutral-300">{{ $item['name'] }}</span>
                            @if (!empty($item['attachments_count']))
                                <span class="flex h-5 items-center justify-center text-neutral-400 dark:text-neutral-500" title="{{ trans_choice(':count attachment|:count attachments', $item['attachments_count'], ['count' => $item['attachments_count']]) }}" data-test="folder-item-attachments">
                                    <flux:icon name="paper-clip" variant="mini" class="size-3.5" />
                                </span>
                            @endif
                            @if (!empty($item['counts']))
                                <div class="flex h-5 flex-wrap items-center justify-center gap-1 overflow-hidden">
                                    @foreach ($item['counts'] as $count)
                                        <flux:badge :color="$count['color']" size="sm" :title="$count['label']">{{ $count['value'] }}</flux:badge>
                                    @endforeach
                                </div>
                            @endif
                            @if (!empty($item['badge']))
                                <div class="h-5 overflow-hidden">
                                    <flux:badge :color="$item['badge']['color']" size="sm">{{ $item['badge']['label'] }}</flux:badge>
                                </div>
                            @endif
                        </a>
                    @endforeach
                </div>
            </template>

            <template x-if="view === 'list'">
                <div>
                    @if (!empty($listColumns))
                        <div class="hidden min-h-9 items-center gap-3 border-b border-neutral-100 px-2 text-xs font-medium text-neutral-400 sm:flex dark:border-white/5 dark:text-neutral-500" data-test="folder-list-sort-header">
                            @foreach ($listColumns as $column)
                                <button
                                    type="button"
                                    x-on:click="sortList('{{ $column['key'] }}')"
                                    @class([
                                        'flex items-center gap-1 text-left hover:text-neutral-700 dark:hover:text-neutral-300',
                                        $column['class'] ?? null,
                                    ])
                                    data-test="folder-list-sort-{{ $column['key'] }}"
                                >
                                    <span>{{ $column['label'] }}</span>
                                    <span class="w-3 text-center" x-text="listSortKey === '{{ $column['key'] }}' ? (listSortDirection === 'asc' ? '↑' : '↓') : ''"></span>
                                </button>
                            @endforeach

                            @if ($hasListBadges)
                                <span class="size-6 shrink-0"></span>
                            @endif
                        </div>
                    @endif

                    <div class="divide-y divide-neutral-100 dark:divide-white/5" x-ref="listRows">
                    @foreach ($items as $item)
                        @php
                            $itemKey = md5($item['href']);
                            $metaByKey = collect($item['meta'] ?? [])->mapWithKeys(fn ($meta) => [$meta['key'] => $meta['value']]);
                            $metaTitlesByKey = collect($item['meta'] ?? [])->mapWithKeys(fn ($meta) => [$meta['key'] => $meta['title'] ?? null]);
                        @endphp
                        <a href="{{ $item['href'] }}" wire:navigate
                           wire:key="folder-list-{{ $itemKey }}"
                           data-sort-index="{{ $loop->index }}"
                           @foreach (($item['sort'] ?? []) as $sortKey => $sortValue)
                               data-sort-{{ $sortKey }}="{{ $sortValue }}"
                           @endforeach
                           class="flex min-h-12 items-center gap-3 rounded-lg px-2 py-2 hover:bg-neutral-100 dark:hover:bg-white/5">
                            @if (!empty($listColumns))
                                @foreach ($listColumns as $column)
                                    @if ($column['key'] === 'name')
                                        <span @class(['min-w-0', $column['class'] ?? 'flex-1'])>
                                            <span class="block truncate text-sm text-neutral-700 dark:text-neutral-300">{{ $item['name'] }}</span>
                                            @if (!empty($item['meta']))
                                                <span class="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-neutral-500 sm:hidden dark:text-neutral-400">
                                                    @foreach ($item['meta'] as $meta)
                                                        <span class="max-w-full truncate" @if (!empty($meta['title'])) title="{{ $meta['title'] }}" @endif>
                                                            <span class="text-neutral-400 dark:text-neutral-500">{{ $meta['label'] }}:</span>
                                                            {{ $meta['value'] }}
                                                        </span>
                                                    @endforeach
                                                </span>
                                            @endif
                                        </span>
                                    @elseif ($column['key'] === 'attachments')
                                        <span @class(['hidden text-xs text-neutral-500 sm:flex sm:items-center dark:text-neutral-400', $column['class'] ?? 'w-12 shrink-0 justify-center'])>
                                            @if (!empty($item['attachments_count']))
                                                <span class="flex size-6 items-center justify-center text-neutral-400 dark:text-neutral-500" title="{{ trans_choice(':count attachment|:count attachments', $item['attachments_count'], ['count' => $item['attachments_count']]) }}" data-test="folder-item-attachments">
                                                    <flux:icon name="paper-clip" variant="mini" class="size-4" />
                                                </span>
                                            @endif
                                        </span>
                                    @else
                                        @php
                                            $columnValue = $metaByKey[$column['key']] ?? null;
                                            $columnTitle = $metaTitlesByKey[$column['key']] ?? null;
                                        @endphp
                                        <span
                                            @class(['hidden truncate text-xs text-neutral-500 sm:block dark:text-neutral-400', $column['class'] ?? null])
                                            @if ($columnTitle) title="{{ $columnTitle }}" @endif
                                        >
                                            @if ($columnValue)
                                                {{ $columnValue }}
                                            @endif
                                        </span>
                                    @endif
                                @endforeach

                                @if ($hasListBadges)
                                    <span class="flex size-6 shrink-0 items-center justify-center">
                                        @if (!empty($item['badge']))
                                        <flux:badge :color="$item['badge']['color']" size="sm" data-test="folder-item-badge">{{ $item['badge']['label'] }}</flux:badge>
                                        @endif
                                    </span>
                                @endif
                            @else
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm text-neutral-700 dark:text-neutral-300">{{ $item['name'] }}</span>
                                    @if (!empty($item['meta']))
                                        <span class="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-neutral-500 sm:hidden dark:text-neutral-400">
                                            @foreach ($item['meta'] as $meta)
                                                <span class="max-w-full truncate" @if (!empty($meta['title'])) title="{{ $meta['title'] }}" @endif>
                                                    <span class="text-neutral-400 dark:text-neutral-500">{{ $meta['label'] }}:</span>
                                                    {{ $meta['value'] }}
                                                </span>
                                            @endforeach
                                        </span>
                                    @endif
                                </span>
                                @if (!empty($item['meta']))
                                    <div class="hidden shrink-0 items-center gap-3 sm:flex">
                                        @foreach ($item['meta'] as $meta)
                                            <span class="w-28 truncate text-xs text-neutral-500 dark:text-neutral-400" @if (!empty($meta['title'])) title="{{ $meta['title'] }}" @endif>
                                                <span class="text-neutral-400 dark:text-neutral-500">{{ $meta['label'] }}:</span>
                                                {{ $meta['value'] }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                                @if (!empty($item['attachments_count']))
                                    <span class="flex size-6 shrink-0 items-center justify-center text-neutral-400 dark:text-neutral-500" title="{{ trans_choice(':count attachment|:count attachments', $item['attachments_count'], ['count' => $item['attachments_count']]) }}" data-test="folder-item-attachments">
                                        <flux:icon name="paper-clip" variant="mini" class="size-4" />
                                    </span>
                                @endif
                                @if (!empty($item['counts']))
                                    <div class="flex shrink-0 items-center gap-1">
                                        @foreach ($item['counts'] as $count)
                                            <flux:badge :color="$count['color']" size="sm" :title="$count['label']">{{ $count['value'] }}</flux:badge>
                                        @endforeach
                                    </div>
                                @endif
                                @if (!empty($item['badge']))
                                    <flux:badge :color="$item['badge']['color']" size="sm" data-test="folder-item-badge">{{ $item['badge']['label'] }}</flux:badge>
                                @endif
                            @endif
                        </a>
                    @endforeach
                    </div>
                </div>
            </template>
        @else
            <div class="flex h-full items-start justify-start pt-4">
                <flux:text class="text-sm text-neutral-400 dark:text-neutral-600">{{ $emptyText }}</flux:text>
            </div>
        @endif
    </div>
</div>
