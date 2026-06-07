@props([
    'post',
    'href' => null,
    'showTopic' => true,
])

@php
    $post->loadMissing(['agentTasks.agent', 'sender.user', 'sender.agent', 'topic']);

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
    $mentionedAgentSlugs = $post->mentionedAgentSlugs();
    $visibleAgentTasks = $post->agentTasks
        ->filter(fn ($task) => $task->event_type === \App\Models\AgentTask::EventPostMentioned)
        ->filter(fn ($task) => $task->agent && $mentionedAgentSlugs->contains($task->agent->slug));
@endphp

<article {{ $attributes->class('flex min-w-0 gap-3') }} data-test="post-message">
    <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-neutral-200 text-sm font-semibold text-neutral-700 dark:bg-zinc-700 dark:text-neutral-100">
        {{ $senderInitials ?: '?' }}
    </div>

    <div class="min-w-0 flex-1">
        <div class="flex min-w-0 items-start justify-between gap-3">
            <div class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-1">
                <span class="min-w-0 truncate text-sm font-semibold text-neutral-900 dark:text-neutral-100" data-test="post-message-sender">{{ $senderName }}</span>
                <span class="text-xs text-neutral-400 dark:text-neutral-500" title="{{ $timestampTitle }}" data-test="post-message-timestamp">{{ $timestampLabel }}</span>

                @if ($showTopic)
                    <a
                        href="{{ route('dashboard', ['topic' => $post->topic->slug, 'panel' => 'posts']) }}"
                        wire:navigate
                        class="text-xs text-neutral-400 hover:text-neutral-700 hover:underline dark:text-neutral-500 dark:hover:text-neutral-300"
                        data-test="post-message-topic"
                    >#{{ $post->topic->name }}</a>
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

        <div class="text-sm leading-[1.2] text-neutral-800 dark:text-neutral-200">
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

        @if ($visibleAgentTasks->isNotEmpty())
            <div class="mt-2 space-y-1 text-xs text-neutral-500 dark:text-neutral-400" data-test="post-message-tasks">
                @foreach ($visibleAgentTasks as $task)
                    <div class="flex items-center gap-2">
                        <flux:icon name="cpu-chip" variant="mini" class="size-3.5 text-amber-500" />
                        <span>{{ $task->agent->name }} {{ $task->status->label() }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</article>
