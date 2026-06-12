@php
    /** @var \App\Models\Brief $brief */
@endphp

<div class="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(22rem,1fr)]" data-test="brief-detail">
    <div class="space-y-4">
        <section class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-white/10 dark:bg-zinc-900">
            <div class="mb-2 flex items-center gap-2">
                <flux:badge color="zinc" size="sm">{{ $brief->category->label() }}</flux:badge>
                @if ($brief->sourceThread)
                    <flux:text class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Source') }}: {{ $brief->sourceThread->title }}</flux:text>
                @endif
            </div>
            <flux:heading size="lg">{{ $brief->summary }}</flux:heading>
        </section>

        <section class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-white/10 dark:bg-zinc-900">
            <flux:heading size="sm">{{ __('Current behaviour') }}</flux:heading>
            <p class="mt-2 whitespace-pre-wrap text-sm text-neutral-700 dark:text-neutral-200">{{ $brief->current_behaviour }}</p>
        </section>

        <section class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-white/10 dark:bg-zinc-900">
            <flux:heading size="sm">{{ __('Expected behaviour') }}</flux:heading>
            <p class="mt-2 whitespace-pre-wrap text-sm text-neutral-700 dark:text-neutral-200">{{ $brief->expected_behaviour }}</p>
        </section>

        @if ($brief->out_of_scope)
            <section class="rounded-lg border border-neutral-200 bg-white p-4 dark:border-white/10 dark:bg-zinc-900">
                <flux:heading size="sm">{{ __('Out of scope') }}</flux:heading>
                <p class="mt-2 whitespace-pre-wrap text-sm text-neutral-700 dark:text-neutral-200">{{ $brief->out_of_scope }}</p>
            </section>
        @endif
    </div>

    <div class="space-y-4">
        <section class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 dark:border-white/10 dark:bg-zinc-950/30">
            <div class="mb-3 flex items-center justify-between gap-3">
                <flux:heading size="sm">{{ __('Acceptance criteria') }}</flux:heading>
                <flux:badge color="zinc" size="sm">{{ count($brief->acceptance_criteria ?? []) }}</flux:badge>
            </div>

            <div class="space-y-2">
                @forelse ($brief->acceptance_criteria ?? [] as $criterion)
                    <div class="rounded-md border border-neutral-200 bg-white p-2 text-sm text-neutral-700 dark:border-white/10 dark:bg-zinc-900 dark:text-neutral-200">
                        <span @class(['line-through text-neutral-400 dark:text-neutral-500' => (bool) ($criterion['done'] ?? false)])>{{ $criterion['text'] ?? '' }}</span>
                    </div>
                @empty
                    <flux:text class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('No acceptance criteria.') }}</flux:text>
                @endforelse
            </div>
        </section>

        <section class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 dark:border-white/10 dark:bg-zinc-950/30">
            <div class="mb-3 flex items-center justify-between gap-3">
                <flux:heading size="sm">{{ __('Plan') }}</flux:heading>
                <div class="flex items-center gap-2">
                    @php
                        $taskCount = $brief->plan?->tasks->count() ?? 0;
                        $doneTaskCount = $brief->plan?->tasks->where('status', \App\Enums\TaskStatus::Done)->count() ?? 0;
                        $planBadgeColor = $taskCount > 0 && $doneTaskCount === $taskCount ? 'green' : 'blue';
                    @endphp

                    <flux:badge :color="$brief->plan ? $planBadgeColor : 'zinc'" size="sm">
                        {{ $brief->plan ? __(':done/:total done', ['done' => $doneTaskCount, 'total' => $taskCount]) : __('No plan') }}
                    </flux:badge>

                    <flux:button :href="route('briefs.plan', $brief)" wire:navigate size="xs" variant="ghost" icon="arrow-right" data-test="brief-plan-card-open">
                        {{ __('Open') }}
                    </flux:button>
                </div>
            </div>

            <a href="{{ route('briefs.plan', $brief) }}" wire:navigate class="block rounded-md transition focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500" data-test="brief-plan-card-link">
                @if ($brief->plan?->summary)
                    <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ $brief->plan->summary }}</p>
                @else
                    <flux:text class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('No plan summary yet.') }}</flux:text>
                @endif
            </a>
        </section>
    </div>
</div>
