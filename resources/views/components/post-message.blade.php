@props([
    'post',
    'showTopic' => true,
    'showReplyAffordance' => false,
    'replyHref' => null,
    'showThreadButton' => false,
    'threadButtonAction' => null,
])

@php
    $post->loadMissing(['attachments', 'sender.user', 'sender.agent', 'thread.topic']);

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
    $hasExpandableBody = substr_count($body, "\n") >= 10 || Str::length($body) > 900;
    $mentionedAgentsBySlug = $post->mentionedAgents()->keyBy('slug');
    $bodyHtml = (function () use ($body, $mentionedAgentsBySlug): \Illuminate\Support\HtmlString {
        $markdown = Str::markdown($body, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $pattern = '/(?<![\w@])@([a-z0-9][a-z0-9-]*)\b/i';

        $html = collect(preg_split('/(<[^>]+>)/', $markdown, flags: PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY))
            ->map(function (string $segment) use ($pattern, $mentionedAgentsBySlug): string {
                if (str_starts_with($segment, '<')) {
                    return $segment;
                }

                return preg_replace_callback($pattern, function (array $matches) use ($mentionedAgentsBySlug): string {
                    $mention = $matches[0];
                    $slug = Str::lower($matches[1]);
                    $agent = $mentionedAgentsBySlug->get($slug);

                    if (! $agent) {
                        return $mention;
                    }

                    return '<a href="'.e(route('dashboard', ['agent' => $agent->slug])).'" wire:navigate class="font-medium text-amber-700 hover:underline dark:text-amber-300" data-test="post-message-agent-mention">'.e($mention).'</a>';
                }, $segment);
            })
            ->implode('');

        return new \Illuminate\Support\HtmlString($html);
    })();
    $replyPosts = collect();

    if ($showReplyAffordance && $post->thread) {
        $replyPosts = $post->thread->posts()
            ->where('posts.id', '!=', $post->id)
            ->with(['sender.user', 'sender.agent'])
            ->get();
    }
@endphp

<article {{ $attributes->class('group/post-message flex min-w-0 gap-3') }} data-test="post-message">
    <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-neutral-200 text-sm font-semibold text-neutral-700 dark:bg-zinc-700 dark:text-neutral-100">
        {{ $senderInitials ?: '?' }}
    </div>

    <div class="min-w-0 flex-1">
        <div class="flex min-w-0 items-start justify-between gap-3">
            <div class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-1">
                <span class="min-w-0 truncate text-sm font-semibold text-neutral-900 dark:text-neutral-100" data-test="post-message-sender">{{ $senderName }}</span>
                <span class="text-xs text-neutral-400 dark:text-neutral-500" title="{{ $timestampTitle }}" data-test="post-message-timestamp">{{ $timestampLabel }}</span>

                @if ($showTopic && $post->thread->topic)
                    <a
                        href="{{ route('dashboard', ['topic' => $post->thread->topic->slug, 'panel' => 'posts']) }}"
                        wire:navigate
                        class="text-xs text-neutral-400 hover:text-neutral-700 hover:underline dark:text-neutral-500 dark:hover:text-neutral-300"
                        data-test="post-message-topic"
                    >#{{ $post->thread->topic->name }}</a>
                @endif
            </div>

            @if (($showThreadButton && $replyHref) || $hasActions)
                <div class="flex shrink-0 items-center gap-1">
                    @if ($showThreadButton && $replyHref)
                        @if ($threadButtonAction)
                            <flux:button
                                type="button"
                                wire:click="{{ $threadButtonAction }}('{{ $post->ulid }}')"
                                variant="subtle"
                                size="xs"
                                square
                                icon="chat-bubble-left"
                                tooltip="{{ __('Open thread') }}"
                                class="opacity-0 transition-opacity group-hover/post-message:opacity-100 focus:opacity-100"
                                data-test="post-message-thread-button"
                            />
                        @else
                            <flux:button
                                href="{{ $replyHref }}"
                                wire:navigate
                                variant="subtle"
                                size="xs"
                                square
                                icon="chat-bubble-left"
                                tooltip="{{ __('Open thread') }}"
                                class="opacity-0 transition-opacity group-hover/post-message:opacity-100 focus:opacity-100"
                                data-test="post-message-thread-button"
                            />
                        @endif
                    @endif

                    @if ($hasActions)
                        <flux:dropdown position="bottom" align="end">
                            <flux:button variant="subtle" size="xs" square icon="ellipsis-horizontal" tooltip="{{ __('Post actions') }}" data-test="post-message-actions" />

                            <flux:menu>
                                {{ $actions }}
                            </flux:menu>
                        </flux:dropdown>
                    @endif
                </div>
            @endif
        </div>

        <div
            class="text-sm leading-[1.2] text-neutral-800 dark:text-neutral-200"
            @if ($hasExpandableBody)
                x-data="{
                    expanded: false,
                    canToggle: false,
                    refresh() {
                        this.$nextTick(() => {
                            this.canToggle = this.$refs.body.scrollHeight > this.$refs.body.clientHeight + 1
                        })
                    },
                }"
                x-init="refresh()"
                x-on:resize.window.debounce.150ms="refresh()"
            @endif
            data-test="post-message-body-wrapper"
        >
            <div
                @if ($hasExpandableBody)
                    x-ref="body"
                    x-bind:class="{ 'max-h-[10.5rem] overflow-hidden': ! expanded }"
                @endif
                @class([
                    'space-y-2 [&_a]:font-medium [&_a]:text-blue-700 [&_a]:hover:underline [&_a]:dark:text-blue-300 [&_blockquote]:border-l-2 [&_blockquote]:border-neutral-200 [&_blockquote]:pl-3 [&_blockquote]:text-neutral-600 [&_blockquote]:dark:border-white/10 [&_blockquote]:dark:text-neutral-300 [&_code]:rounded [&_code]:bg-neutral-100 [&_code]:px-1 [&_code]:py-0.5 [&_code]:text-[0.82em] [&_code]:dark:bg-white/10 [&_ol]:list-decimal [&_ol]:space-y-1 [&_ol]:pl-5 [&_p]:m-0 [&_pre]:overflow-auto [&_pre]:rounded-md [&_pre]:bg-neutral-100 [&_pre]:p-3 [&_pre]:dark:bg-white/10 [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:pl-5',
                    'text-neutral-400 dark:text-neutral-600' => ! $post->body,
                ])
                data-test="post-message-body"
            >{!! $bodyHtml !!}</div>

            @if ($hasExpandableBody)
                <button
                    type="button"
                    class="mt-1 hidden cursor-pointer text-xs font-medium text-blue-700 hover:text-blue-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-blue-300 dark:hover:text-blue-200"
                    x-bind:class="{ 'inline-flex': canToggle }"
                    x-on:click.stop="expanded = ! expanded"
                    data-test="post-message-body-toggle"
                >
                    <span x-show="! expanded">{{ __('Show more') }}</span>
                    <span x-show="expanded">{{ __('Show less') }}</span>
                </button>
            @endif
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
                            class="group flex w-40 flex-col overflow-hidden rounded-md border border-neutral-200 bg-neutral-50 hover:border-neutral-300 dark:border-white/10 dark:bg-white/5 dark:hover:border-white/20"
                            title="{{ $attachment->filename }} · {{ $attachment->formattedSize() }}"
                            data-test="post-message-attachment"
                        >
                            <span class="flex aspect-video w-full items-center justify-center bg-white dark:bg-zinc-900/60">
                                <flux:icon name="document" class="size-8 text-neutral-400 group-hover:text-neutral-600 dark:text-neutral-500 dark:group-hover:text-neutral-300" />
                            </span>
                            <span class="min-w-0 px-2 py-1">
                                <span class="block truncate text-xs text-neutral-600 group-hover:text-neutral-900 dark:text-neutral-300 dark:group-hover:text-neutral-100">{{ $attachment->filename }}</span>
                                <span class="block text-xs text-neutral-400">{{ $attachment->formattedSize() }}</span>
                            </span>
                        </a>
                    @endif
                @endforeach
            </div>
        @endif

        @if ($showReplyAffordance && $replyPosts->isNotEmpty())
            @php
                $lastReply = $replyPosts->sortByDesc('created_at')->first();
            @endphp

            <a
                @if ($replyHref)
                    href="{{ $replyHref }}"
                    wire:navigate
                @endif
                @class([
                    'mt-2.5 inline-flex items-center gap-1.5 text-xs',
                    'cursor-pointer rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500' => $replyHref,
                ])
                data-test="post-message-replies"
            >
                <div class="flex -space-x-1">
                    @foreach ($replyPosts->take(3) as $replyPost)
                        @php
                            $replySenderName = $replyPost->sender?->label() ?? __('Unknown sender');
                            $replySenderInitials = Str::of($replySenderName)
                                ->explode(' ')
                                ->filter()
                                ->take(2)
                                ->map(fn (string $word): string => Str::substr($word, 0, 1))
                                ->implode('');
                        @endphp

                        <span
                            class="flex size-5 items-center justify-center rounded border-2 border-white bg-neutral-200 text-[0.58rem] font-semibold leading-none text-neutral-700 dark:border-zinc-900 dark:bg-zinc-700 dark:text-neutral-100"
                            title="{{ $replySenderName }}"
                            data-test="post-message-reply-avatar"
                        >
                            {{ $replySenderInitials ?: '?' }}
                        </span>
                    @endforeach
                </div>

                <span class="font-medium text-blue-700 dark:text-blue-300">
                    {{ trans_choice(':count reply|:count replies', $replyPosts->count(), ['count' => $replyPosts->count()]) }}
                </span>

                @if ($lastReply)
                    <span class="text-neutral-500 dark:text-neutral-400">
                        {{ __('Last reply :time', ['time' => $lastReply->created_at->diffForHumans()]) }}
                    </span>
                @endif
            </a>
        @endif

    </div>
</article>
