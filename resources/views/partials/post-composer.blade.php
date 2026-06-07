@props([
    'bodyModel',
    'buttonTest',
    'dataTest',
    'placeholder',
    'submitAction',
])

<form wire:submit="{{ $submitAction }}" class="w-full" data-test="{{ $dataTest }}">
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
            <div class="min-h-4 min-w-0 flex-1">
                @error($bodyModel)
                    <p class="truncate text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <flux:button type="submit" size="xs" variant="primary" icon="paper-airplane" data-test="{{ $buttonTest }}">
                {{ __('Send') }}
            </flux:button>
        </div>
    </div>
</form>
