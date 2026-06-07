<flux:select.option value="">{{ __('None') }}</flux:select.option>

@foreach (\App\Enums\ReasoningEffort::cases() as $effort)
    <flux:select.option :value="$effort->value">{{ $effort->label() }}</flux:select.option>
@endforeach
