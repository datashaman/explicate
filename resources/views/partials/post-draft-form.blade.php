@props([
    'formId',
    'submitAction',
    'titleModel',
    'titleTest' => null,
    'bodyModel',
    'bodyTest' => null,
    'topicModel' => null,
    'topicName' => null,
    'agentIdsModel',
    'availableTopics' => collect(),
    'availableAgents' => collect(),
    'canChangeTopic' => false,
    'testPrefix' => null,
    'post' => null,
    'uploadModel',
    'uploadError',
    'deleteAction' => null,
    'returnHref' => null,
    'saveTest' => null,
    'publishAction',
    'publishTest' => null,
    'archiveAction' => null,
    'loadingTarget',
    'dataTest' => null,
])

<div class="flex flex-1 flex-col gap-6 overflow-auto px-4 py-4 xl:min-h-0" @if ($dataTest) data-test="{{ $dataTest }}" @endif>
    <form id="{{ $formId }}" wire:submit="{{ $submitAction }}" class="flex flex-col gap-6">
        <flux:input wire:model="{{ $titleModel }}" :label="__('Title')" required data-test="{{ $titleTest }}" />

        @include('partials.post-routing-fields', [
            'topicModel' => $topicModel,
            'topicName' => $topicName,
            'agentIdsModel' => $agentIdsModel,
            'availableTopics' => $availableTopics,
            'availableAgents' => $availableAgents,
            'canChangeTopic' => $canChangeTopic,
            'testPrefix' => $testPrefix,
        ])

        <flux:textarea wire:model="{{ $bodyModel }}" :label="__('Body')" :placeholder="__('Write something...')" rows="12" data-test="{{ $bodyTest }}" />
    </form>

    @if ($post)
        @include('partials.post-attachments', [
            'post' => $post,
            'uploadModel' => $uploadModel,
            'uploadError' => $uploadError,
            'deleteAction' => $deleteAction,
        ])
    @else
        <div class="flex flex-col gap-3">
            <flux:heading size="sm">{{ __('Attachments') }}</flux:heading>

            <div x-data="{ uploading: false, progress: 0 }"
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
            </div>
        </div>
    @endif

    <div class="flex flex-wrap justify-end gap-2">
        @if ($returnHref)
            <flux:button :href="$returnHref" wire:navigate variant="filled">
                {{ __('Cancel') }}
            </flux:button>
        @endif

        @if ($archiveAction)
            <flux:button wire:click="{{ $archiveAction }}" type="button" variant="filled" icon="archive-box" icon:variant="outline">{{ __('Archive') }}</flux:button>
        @endif

        <flux:button type="submit" form="{{ $formId }}" variant="filled" data-test="{{ $saveTest }}" wire:loading.attr="disabled" wire:target="{{ $loadingTarget }}">{{ __('Save draft') }}</flux:button>
        <flux:button wire:click="{{ $publishAction }}" type="button" variant="primary" icon="paper-airplane" data-test="{{ $publishTest }}" wire:loading.attr="disabled" wire:target="{{ $loadingTarget }}">{{ __('Post') }}</flux:button>
    </div>
</div>
