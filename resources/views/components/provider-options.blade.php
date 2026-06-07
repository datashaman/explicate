@foreach (\App\Enums\Provider::cases() as $provider)
    <flux:select.option :value="$provider->value">{{ $provider->label() }}</flux:select.option>
@endforeach
