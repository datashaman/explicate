@props([
    'targetModel',
    'targetValue',
    'topicModel' => null,
    'topicName' => null,
    'recipientModel',
    'agentIdsModel',
    'availableTopics' => collect(),
    'availableRecipients' => collect(),
    'availableAgents' => collect(),
    'canChangeTopic' => false,
    'testPrefix' => null,
])

<div class="grid grid-cols-1 gap-4 sm:grid-cols-[10rem_minmax(0,1fr)]">
    <flux:select wire:model.live="{{ $targetModel }}" :label="__('To')" required data-test="{{ $testPrefix ? $testPrefix.'-target' : '' }}">
        <flux:select.option value="topic">{{ __('Topic') }}</flux:select.option>
        <flux:select.option value="principal">{{ __('User') }}</flux:select.option>
    </flux:select>

    @if ($canChangeTopic)
        <flux:select wire:model="{{ $topicModel }}" :label="__('Topic')" placeholder="{{ __('Select a topic…') }}" required data-test="{{ $testPrefix ? $testPrefix.'-topic' : '' }}">
            @foreach ($availableTopics as $topic)
                <flux:select.option :value="$topic->id">{{ $topic->name }}</flux:select.option>
            @endforeach
        </flux:select>
    @else
        <flux:input :label="__('Topic')" :value="$topicName" readonly />
    @endif
</div>

@if ($targetValue === 'principal')
    <flux:select wire:model="{{ $recipientModel }}" :label="__('Recipient')" placeholder="{{ __('Select a user…') }}" required data-test="{{ $testPrefix ? $testPrefix.'-recipient' : '' }}">
        @foreach ($availableRecipients as $recipient)
            <flux:select.option :value="$recipient->id">
                {{ $recipient->label() }}
            </flux:select.option>
        @endforeach
    </flux:select>
@endif

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
