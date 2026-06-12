<aside class="flex min-h-0 flex-col border-b border-neutral-200 lg:border-b-0 lg:border-r dark:border-white/10">
    <div class="flex items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3 dark:border-white/10">
        <flux:heading size="sm">{{ __('Briefs') }}</flux:heading>
        <flux:button :href="route('briefs.create')" wire:navigate size="xs" icon="plus" variant="primary" data-test="new-brief-button">
            {{ __('New brief') }}
        </flux:button>
    </div>

    <div class="min-h-0 flex-1 overflow-auto p-2">
        @forelse ($briefs as $brief)
            <a
                href="{{ route('briefs.show', $brief) }}"
                wire:navigate
                @class([
                    'flex w-full items-start gap-3 rounded-lg border px-3 py-2 text-left transition',
                    'border-emerald-500 bg-emerald-100 shadow-sm shadow-emerald-950/10 ring-1 ring-emerald-500/30 dark:border-emerald-400 dark:bg-emerald-500/20 dark:shadow-none dark:ring-emerald-300/25' => $selectedBriefId === $brief->id,
                    'border-transparent' => $selectedBriefId !== $brief->id,
                    'hover:bg-neutral-100 dark:hover:bg-white/5' => $selectedBriefId !== $brief->id,
                ])
                wire:key="brief-row-{{ $brief->id }}"
                data-test="brief-row-{{ $brief->id }}"
            >
                <flux:icon name="{{ $brief->category === \App\Enums\BriefCategory::Bug ? 'bug-ant' : 'sparkles' }}" class="mt-0.5 size-4 shrink-0 text-neutral-500 dark:text-neutral-400" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium text-neutral-800 dark:text-neutral-100">{{ $brief->summary }}</span>
                    <span class="mt-1 flex items-center gap-2 text-xs text-neutral-500 dark:text-neutral-400">
                        <span>{{ $brief->category->label() }}</span>
                        @if ($brief->sourceThread)
                            <span class="truncate">{{ $brief->sourceThread->title }}</span>
                        @endif
                    </span>
                    <span class="mt-2 inline-flex">
                        @if ($brief->plan)
                            @php
                                $taskCount = $brief->plan->tasks->count();
                                $doneTaskCount = $brief->plan->tasks->where('status', \App\Enums\TaskStatus::Done)->count();
                            @endphp
                            <flux:badge :color="$taskCount > 0 && $doneTaskCount === $taskCount ? 'green' : 'blue'" size="sm">
                                {{ __(':done/:total done', ['done' => $doneTaskCount, 'total' => $taskCount]) }}
                            </flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm">{{ __('No plan') }}</flux:badge>
                        @endif
                    </span>
                </span>
            </a>
        @empty
            <div class="px-3 py-8 text-center">
                <flux:text class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('No briefs yet.') }}</flux:text>
            </div>
        @endforelse
    </div>
</aside>
