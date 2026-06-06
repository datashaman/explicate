@php
    /** @var \App\Models\Post $post */
    $canManageAttachments = $post->status === \App\Enums\PostStatus::Draft;
@endphp

@if ($post->attachments->isNotEmpty() || $canManageAttachments)
    <div class="flex flex-col gap-3">
        <flux:heading size="sm">{{ __('Attachments') }}</flux:heading>

        @if ($post->attachments->isNotEmpty())
            <div class="divide-y divide-neutral-100 rounded-lg border border-neutral-200 dark:divide-white/5 dark:border-white/10">
                @foreach ($post->attachments as $attachment)
                    <div class="flex items-center gap-3 px-3 py-2" wire:key="post-attachment-{{ $attachment->id }}">
                        <flux:icon name="paper-clip" class="size-4 shrink-0 text-neutral-400" />
                        <a href="{{ $attachment->url() }}" target="_blank"
                           class="flex-1 truncate text-sm text-neutral-700 hover:underline dark:text-neutral-300">
                            {{ $attachment->filename }}
                        </a>
                        <flux:text class="shrink-0 text-xs text-neutral-400">{{ $attachment->formattedSize() }}</flux:text>

                        @if ($canManageAttachments)
                            <flux:button
                                wire:click="{{ $deleteAction }}({{ $attachment->id }})"
                                wire:confirm="{{ __('Delete this attachment?') }}"
                                icon="trash"
                                variant="ghost"
                                size="xs"
                                class="text-neutral-400 hover:text-red-500"
                            />
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($canManageAttachments)
            <form wire:submit="{{ $uploadAction }}"
                  x-data="{ uploading: false, progress: 0 }"
                  x-on:livewire-upload-start="uploading = true"
                  x-on:livewire-upload-finish="uploading = false"
                  x-on:livewire-upload-error="uploading = false"
                  x-on:livewire-upload-progress="progress = $event.detail.progress"
                  class="flex flex-col gap-2">
                <flux:input type="file" wire:model="{{ $uploadModel }}" multiple />

                <div x-show="uploading" class="h-1 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-white/10">
                    <div class="h-full rounded-full bg-blue-500 transition-all" :style="`width: ${progress}%`"></div>
                </div>

                @error($uploadError)
                    <flux:error>{{ $message }}</flux:error>
                @enderror

                <div class="flex justify-end">
                    <flux:button type="submit" size="sm" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="{{ $uploadModel }}">{{ __('Upload') }}</flux:button>
                </div>
            </form>
        @endif
    </div>
@endif
