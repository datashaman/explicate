@props([
    'topicModel' => null,
    'topicName' => null,
    'availableTopics' => collect(),
    'canChangeTopic' => false,
    'testPrefix' => null,
])

<div>
    @if ($canChangeTopic)
        <flux:select wire:model="{{ $topicModel }}" :label="__('Post in topic')" placeholder="{{ __('Select a topic…') }}" required data-test="{{ $testPrefix ? $testPrefix.'-topic' : '' }}">
            @foreach ($availableTopics as $topic)
                <flux:select.option :value="$topic->id">{{ $topic->name }}</flux:select.option>
            @endforeach
        </flux:select>
    @else
        <flux:input :label="__('Post in topic')" :value="$topicName" readonly />
    @endif
</div>
