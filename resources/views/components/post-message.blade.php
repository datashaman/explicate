@props([
    'post',
    'href' => null,
    'showTopic' => true,
])

@php
    $post->loadMissing(['assignedAgents', 'sender.user', 'sender.agent', 'topic']);

    $senderName = $post->sender?->label() ?? __('Unknown sender');
    $senderInitials = Str::of($senderName)
        ->explode(' ')
        ->filter()
        ->take(2)
        ->map(fn (string $word): string => Str::substr($word, 0, 1))
        ->implode('');
    $timezone = auth()->user()?->displayTimezone() ?? config('app.timezone');
    $timestamp = $post->status === \App\Enums\PostStatus::Draft ? $post->updated_at : $post->created_at;
    $timestampLabel = $timestamp->diffForHumans();
    $timestampTitle = $timestamp->timezone($timezone)->isoFormat('LLLL');
    $hasActions = isset($actions) && trim($actions->toHtml()) !== '';
    $body = $post->body ?: __('No content.');
@endphp

<article {{ $attributes->class('flex min-w-0 gap-3') }} data-test="post-message">
    <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-neutral-200 text-sm font-semibold text-neutral-700 dark:bg-zinc-700 dark:text-neutral-100">
        {{ $senderInitials ?: '?' }}
    </div>

    <div class="min-w-0 flex-1 space-y-2">
        <div class="flex min-w-0 items-start justify-between gap-3">
            <div class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-1">
                <span class="min-w-0 truncate text-sm font-semibold text-neutral-900 dark:text-neutral-100" data-test="post-message-sender">{{ $senderName }}</span>
                <span class="text-xs text-neutral-400 dark:text-neutral-500" title="{{ $timestampTitle }}" data-test="post-message-timestamp">{{ $timestampLabel }}</span>

                @if ($showTopic)
                    <span class="text-xs text-neutral-400 dark:text-neutral-500">#{{ $post->topic->name }}</span>
                @endif
            </div>

            @if ($hasActions)
                <flux:dropdown position="bottom" align="end">
                    <flux:button variant="subtle" size="xs" square icon="ellipsis-horizontal" tooltip="{{ __('Post actions') }}" data-test="post-message-actions" />

                    <flux:menu>
                        {{ $actions }}
                    </flux:menu>
                </flux:dropdown>
            @endif
        </div>

        <div class="text-sm leading-6 text-neutral-800 dark:text-neutral-200">
            @if ($href)
                <a href="{{ $href }}" wire:navigate @class([
                    'block whitespace-pre-wrap hover:underline',
                    'text-neutral-400 dark:text-neutral-600' => ! $post->body,
                ])>{{ $body }}</a>
            @else
                <div @class([
                    'whitespace-pre-wrap',
                    'text-neutral-400 dark:text-neutral-600' => ! $post->body,
                ])>{{ $body }}</div>
            @endif
        </div>

        @if ($post->assignedAgents->isNotEmpty())
            <div class="flex flex-wrap gap-2">
                @foreach ($post->assignedAgents as $agent)
                    <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-400/20">
                        {{ $agent->name }}
                    </span>
                @endforeach
            </div>
        @endif
    </div>
</article>
