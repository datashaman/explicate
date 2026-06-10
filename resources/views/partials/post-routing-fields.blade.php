@props([
    'topicModel' => null,
    'topicName' => null,
    'availableTopics' => collect(),
    'canChangeTopic' => false,
    'testPrefix' => null,
])

<div>
    @if ($canChangeTopic)
        <flux:select wire:model="{{ $topicModel }}" :label="__('Topic label')" placeholder="{{ __('No topic') }}" data-test="{{ $testPrefix ? $testPrefix.'-topic' : '' }}">
            <flux:select.option value="">{{ __('No topic') }}</flux:select.option>
            @foreach ($availableTopics as $topic)
                <flux:select.option :value="$topic->id">{{ $topic->name }}</flux:select.option>
            @endforeach
        </flux:select>
    @else
        <flux:input :label="__('Topic label')" :value="$topicName" readonly />
    @endif
</div>
