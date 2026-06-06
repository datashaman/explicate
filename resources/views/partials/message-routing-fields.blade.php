@props([
    'topicModel' => null,
    'topicName' => null,
    'agentIdsModel',
    'availableTopics' => collect(),
    'availableAgents' => collect(),
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

@if ($availableAgents->isNotEmpty())
    <div class="flex flex-col gap-2">
        <flux:label>{{ __('Request agent work') }}</flux:label>

        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            @foreach ($availableAgents as $agent)
                <flux:checkbox
                    wire:model="{{ $agentIdsModel }}"
                    :value="$agent->id"
                    :label="$agent->name"
                    data-test="{{ $testPrefix ? $testPrefix.'-agent-'.$agent->slug : '' }}"
                />
            @endforeach
        </div>
    </div>
@endif
