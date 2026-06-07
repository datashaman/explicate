@props([
    'post',
    'showTopic' => true,
])

@php
    $post->loadMissing(['agentTasks.agent', 'attachments', 'sender.user', 'sender.agent', 'topic']);

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
    $mentionedAgentsBySlug = $post->mentionedAgents()->keyBy('slug');
    $bodyHtml = (function () use ($body, $mentionedAgentsBySlug): \Illuminate\Support\HtmlString {
        $pattern = '/(?<![\w@])@([a-z0-9][a-z0-9-]*)\b/i';
        $offset = 0;
        $html = '';

        preg_match_all($pattern, $body, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $index => [$mention, $position]) {
            $html .= e(substr($body, $offset, $position - $offset));

            $slug = Str::lower($matches[1][$index][0]);
            $agent = $mentionedAgentsBySlug->get($slug);

            if ($agent) {
                $html .= '<a href="'.e(route('dashboard', ['agent' => $agent->slug])).'" wire:navigate class="font-medium text-amber-700 hover:underline dark:text-amber-300" data-test="post-message-agent-mention">'.e($mention).'</a>';
            } else {
                $html .= e($mention);
            }

            $offset = $position + strlen($mention);
        }

        $html .= e(substr($body, $offset));

        return new \Illuminate\Support\HtmlString($html);
    })();
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
            <div @class([
                'whitespace-pre-wrap',
                'text-neutral-400 dark:text-neutral-600' => ! $post->body,
            ])>{!! $bodyHtml !!}</div>
        </div>

        @if ($post->attachments->isNotEmpty())
            <div class="mt-2 flex flex-wrap gap-2" data-test="post-message-attachments">
                @foreach ($post->attachments as $attachment)
                    @if ($attachment->isImage())
                        <a
                            href="{{ $attachment->url() }}"
                            target="_blank"
                            class="group block max-w-40 overflow-hidden rounded-md border border-neutral-200 bg-neutral-50 hover:border-neutral-300 dark:border-white/10 dark:bg-white/5 dark:hover:border-white/20"
                            title="{{ $attachment->filename }} · {{ $attachment->formattedSize() }}"
                            data-test="post-message-image-attachment"
                        >
                            <img
                                src="{{ $attachment->url() }}"
                                alt="{{ $attachment->filename }}"
                                class="aspect-video w-40 object-cover"
                            >
                            <span class="block truncate px-2 py-1 text-xs text-neutral-500 group-hover:text-neutral-800 dark:text-neutral-400 dark:group-hover:text-neutral-200">
                                {{ $attachment->filename }}
                            </span>
                        </a>
                    @else
                        <a
                            href="{{ $attachment->url() }}"
                            target="_blank"
                            class="inline-flex max-w-full items-center gap-1.5 rounded-md border border-neutral-200 bg-neutral-50 px-2 py-1 text-xs text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900 dark:border-white/10 dark:bg-white/5 dark:text-neutral-300 dark:hover:bg-white/10"
                            title="{{ $attachment->filename }} · {{ $attachment->formattedSize() }}"
                            data-test="post-message-attachment"
                        >
                            <flux:icon name="paper-clip" variant="mini" class="size-3.5 shrink-0 text-neutral-400" />
                            <span class="truncate">{{ $attachment->filename }}</span>
                            <span class="shrink-0 text-neutral-400">{{ $attachment->formattedSize() }}</span>
                        </a>
                    @endif
                @endforeach
            </div>
        @endif

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
