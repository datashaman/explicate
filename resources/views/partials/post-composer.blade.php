@props([
    'bodyModel',
    'buttonTest',
    'dataTest',
    'loadingTarget' => null,
    'placeholder',
    'removeUploadAction' => null,
    'submitAction',
    'uploadError' => null,
    'uploadModel' => null,
])

@php
    $pendingUploads = $uploadModel ? data_get($this, $uploadModel, []) : [];
    $inputId = $dataTest.'-attachments';
@endphp

<form
    wire:submit="{{ $submitAction }}"
    class="w-full"
    data-test="{{ $dataTest }}"
    x-data="{ uploading: false, progress: 0 }"
    x-on:livewire-upload-start="uploading = true"
    x-on:livewire-upload-finish="uploading = false"
    x-on:livewire-upload-error="uploading = false"
    x-on:livewire-upload-progress="progress = $event.detail.progress"
>
    <div class="rounded-lg border border-neutral-300 bg-white shadow-sm transition focus-within:border-blue-400 focus-within:ring-2 focus-within:ring-blue-100 dark:border-white/10 dark:bg-zinc-950 dark:focus-within:border-blue-400 dark:focus-within:ring-blue-400/10">
        <textarea
            wire:model="{{ $bodyModel }}"
            wire:keydown.meta.enter.prevent="{{ $submitAction }}"
            wire:keydown.ctrl.enter.prevent="{{ $submitAction }}"
            rows="2"
            placeholder="{{ $placeholder }}"
            class="block max-h-40 min-h-18 w-full resize-none overflow-auto border-0 bg-transparent px-3 py-2.5 text-sm leading-5 text-neutral-900 placeholder:text-neutral-400 focus:outline-none focus:ring-0 dark:text-neutral-100 dark:placeholder:text-neutral-600"
            data-test="{{ $dataTest }}-textarea"
        ></textarea>

        <div class="flex items-center justify-between gap-3 border-t border-neutral-100 px-2 py-2 dark:border-white/10">
            <div class="flex min-h-4 min-w-0 flex-1 items-center gap-2">
                @if ($uploadModel)
                    <input id="{{ $inputId }}" type="file" wire:model="{{ $uploadModel }}" multiple class="sr-only" data-test="{{ $dataTest }}-attachments-input">
                    <label
                        for="{{ $inputId }}"
                        class="inline-flex size-7 cursor-pointer items-center justify-center rounded-md text-neutral-500 transition hover:bg-neutral-100 hover:text-neutral-800 dark:text-neutral-400 dark:hover:bg-white/10 dark:hover:text-neutral-100"
                        data-test="{{ $dataTest }}-attachments-button"
                    >
                        <flux:icon name="paper-clip" class="size-4" />
                    </label>
                @endif

                <div class="min-w-0 flex-1">
                    @if (count($pendingUploads) > 0)
                        <div class="flex min-w-0 flex-wrap gap-1.5" data-test="{{ $dataTest }}-attachments-list">
                            @foreach ($pendingUploads as $uploadIndex => $pendingUpload)
                                <span class="inline-flex max-w-44 items-center gap-1 rounded border border-neutral-200 bg-neutral-50 py-0.5 pl-1.5 pr-0.5 text-xs text-neutral-600 dark:border-white/10 dark:bg-white/5 dark:text-neutral-300">
                                    <span class="truncate">{{ $pendingUpload->getClientOriginalName() }}</span>

                                    @if ($removeUploadAction)
                                        <button
                                            type="button"
                                            wire:click="{{ $removeUploadAction }}({{ $uploadIndex }})"
                                            class="inline-flex size-4 shrink-0 cursor-pointer items-center justify-center rounded text-neutral-400 transition hover:bg-neutral-200 hover:text-neutral-800 dark:hover:bg-white/10 dark:hover:text-neutral-100"
                                            data-test="{{ $dataTest }}-attachment-remove"
                                            aria-label="{{ __('Remove attachment') }}"
                                        >
                                            <flux:icon name="x-mark" class="size-3" />
                                        </button>
                                    @endif
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>

                @error($bodyModel)
                    <p class="truncate text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror

                @if ($uploadError)
                    @error($uploadError)
                        <p class="truncate text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                @endif
            </div>

            @if ($loadingTarget)
                <flux:button type="submit" size="xs" variant="primary" icon="paper-airplane" data-test="{{ $buttonTest }}" wire:loading.attr="disabled" wire:target="{{ $loadingTarget }}">
                    {{ __('Send') }}
                </flux:button>
            @else
                <flux:button type="submit" size="xs" variant="primary" icon="paper-airplane" data-test="{{ $buttonTest }}">
                    {{ __('Send') }}
                </flux:button>
            @endif
        </div>

        @if ($uploadModel)
            <div x-show="uploading" class="h-1 w-full overflow-hidden rounded-b-lg bg-neutral-100 dark:bg-white/10">
                <div class="h-full rounded-full bg-blue-500 transition-all" :style="`width: ${progress}%`"></div>
            </div>
        @endif
    </div>
</form>
