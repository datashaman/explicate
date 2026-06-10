<?php

use App\Actions\Agents\CreateAgent;
use App\Actions\Onboarding\SetupNewUser;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\Provider;
use App\Models\ProviderKey;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth'), Title('Setup')] class extends Component
{
    public int $step = 1;

    // Step 1 — Workspace
    public string $workspaceName = 'My Workspace';

    // Step 2 — Topic
    public string $topicName = 'General';

    // Step 3 — API Key
    public string $keyProvider = '';
    public string $keyValue = '';

    // Step 4 — Agent
    public string $agentName = 'Analyst';
    public string $agentProvider = '';
    public string $agentModel = '';
    public string $agentPrompt = "You are a spec analyst. Given a user's input or idea, produce a clear, structured specification: define the goal, list constraints and assumptions, break it into requirements, and call out open questions. Be concise and precise.";

    public function mount(): void
    {
        if (! Auth::user()->needsOnboarding()) {
            $this->redirectRoute('dashboard', navigate: true);
        }

        $this->keyProvider = Provider::Anthropic->value;
        $this->refreshAgentProviderDefaults();
    }

    /** @return list<array{value: string, label: string}> */
    #[Computed]
    public function availableProviders(): array
    {
        $configured = Auth::user()
            ->currentTeam
            ->providerKeys()
            ->whereNull('workspace_id')
            ->pluck('provider')
            ->map(fn (Provider $p) => $p->value)
            ->all();

        return collect(Provider::cases())
            ->filter(fn (Provider $p) => in_array($p->value, $configured))
            ->map(fn (Provider $p) => ['value' => $p->value, 'label' => $p->label()])
            ->values()
            ->all();
    }

    public function updatedKeyProvider(): void
    {
        $this->reset('keyValue');
    }

    public function updatedAgentProvider(string $value): void
    {
        $provider = Provider::tryFrom($value);
        $this->agentModel = $provider ? $provider->models()[0] : '';
    }

    public function next(): void
    {
        $this->validateStep();

        if ($this->step === 3) {
            $this->saveApiKey();
            unset($this->availableProviders);
            $this->refreshAgentProviderDefaults();
        }

        $this->step++;
    }

    public function back(): void
    {
        $this->step--;
    }

    public function skip(SetupNewUser $setupNewUser): void
    {
        $setupNewUser->handle(Auth::user());
        $this->redirectRoute('dashboard', navigate: true);
    }

    public function complete(CreateWorkspace $createWorkspace, CreateAgent $createAgent): void
    {
        $this->validateStep();

        $user = Auth::user();
        $team = $user->currentTeam;

        $workspace = $createWorkspace->handle($team, $this->workspaceName);
        $user->switchWorkspace($workspace);

        $workspace->topics()->create(['name' => $this->topicName]);

        $createAgent->handle(
            workspace: $workspace,
            name: $this->agentName,
            provider: $this->agentProvider,
            model: $this->agentModel,
            reasoningEffort: null,
            prompt: $this->agentPrompt,
        );

        $this->redirectRoute('dashboard', navigate: true);
    }

    private function saveApiKey(): void
    {
        ProviderKey::updateOrCreate(
            [
                'team_id' => Auth::user()->currentTeam->id,
                'workspace_id' => null,
                'provider' => $this->keyProvider,
            ],
            ['api_key' => $this->keyValue],
        );
    }

    private function refreshAgentProviderDefaults(): void
    {
        $first = $this->availableProviders[0] ?? null;
        $this->agentProvider = $first ? $first['value'] : Provider::Anthropic->value;
        $provider = Provider::tryFrom($this->agentProvider);
        $this->agentModel = $provider ? $provider->models()[0] : '';
    }

    private function validateStep(): void
    {
        match ($this->step) {
            1 => $this->validate(['workspaceName' => 'required|string|max:255']),
            2 => $this->validate(['topicName' => 'required|string|max:255']),
            3 => $this->validate([
                'keyProvider' => ['required', Rule::enum(Provider::class)],
                'keyValue' => 'required|string|min:10',
            ], [], [
                'keyProvider' => __('provider'),
                'keyValue' => __('API key'),
            ]),
            4 => $this->validate([
                'agentName' => 'required|string|max:255',
                'agentProvider' => ['required', Rule::enum(Provider::class)],
                'agentModel' => 'required|string|max:255',
                'agentPrompt' => 'required|string',
            ]),
            default => null,
        };
    }
};
?>

<div class="flex flex-col gap-6">

    {{-- Progress --}}
    <div class="flex items-center gap-2">
        @foreach ([1 => 'Workspace', 2 => 'Topic', 3 => 'API Key', 4 => 'Agent'] as $n => $label)
            <div class="flex items-center gap-2 {{ $n < 4 ? 'flex-1' : '' }}">
                <div @class([
                    'flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                    'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $step >= $n,
                    'border border-zinc-300 text-zinc-400 dark:border-zinc-600' => $step < $n,
                ])>{{ $n }}</div>
                <span @class([
                    'hidden sm:inline text-sm font-medium',
                    'text-zinc-900 dark:text-white' => $step >= $n,
                    'text-zinc-400 dark:text-zinc-500' => $step < $n,
                ])>{{ $label }}</span>
                @if ($n < 4)
                    <div class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700 mx-1"></div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Step 1: Workspace --}}
    @if ($step === 1)
        <div class="flex flex-col gap-1">
            <flux:heading size="lg">{{ __('Name your workspace') }}</flux:heading>
            <flux:subheading>{{ __('A workspace holds your topics, posts, and agents. You can rename it later.') }}</flux:subheading>
        </div>

        <flux:input
            wire:model="workspaceName"
            :label="__('Workspace name')"
            autofocus
            required
        />

        <div class="flex items-center justify-between">
            <flux:button wire:click="skip" variant="ghost">{{ __('Skip setup') }}</flux:button>
            <flux:button wire:click="next" variant="primary">{{ __('Next') }}</flux:button>
        </div>
    @endif

    {{-- Step 2: Topic --}}
    @if ($step === 2)
        <div class="flex flex-col gap-1">
            <flux:heading size="lg">{{ __('Create your first topic') }}</flux:heading>
            <flux:subheading>{{ __('Topics organise your posts. Think of them as channels or categories.') }}</flux:subheading>
        </div>

        <flux:input
            wire:model="topicName"
            :label="__('Topic name')"
            autofocus
            required
        />

        <div class="flex items-center justify-between">
            <flux:button wire:click="back" variant="ghost">{{ __('Back') }}</flux:button>
            <div class="flex gap-2">
                <flux:button wire:click="skip" variant="ghost">{{ __('Skip setup') }}</flux:button>
                <flux:button wire:click="next" variant="primary">{{ __('Next') }}</flux:button>
            </div>
        </div>
    @endif

    {{-- Step 3: API Key --}}
    @if ($step === 3)
        <div class="flex flex-col gap-1">
            <flux:heading size="lg">{{ __('Add an API key') }}</flux:heading>
            <flux:subheading>{{ __('Your agents need a provider API key to generate content. Keys are stored encrypted and never shared.') }}</flux:subheading>
        </div>

        <div class="flex flex-col gap-4">
            <flux:select wire:model.live="keyProvider" :label="__('Provider')">
                @foreach (App\Enums\Provider::cases() as $provider)
                    <flux:select.option :value="$provider->value">{{ $provider->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model="keyValue"
                :label="__('API key')"
                type="password"
                placeholder="sk-..."
                required
            />
        </div>

        <div class="flex items-center justify-between">
            <flux:button wire:click="back" variant="ghost">{{ __('Back') }}</flux:button>
            <div class="flex gap-2">
                <flux:button wire:click="skip" variant="ghost">{{ __('Skip setup') }}</flux:button>
                <flux:button wire:click="next" variant="primary">{{ __('Next') }}</flux:button>
            </div>
        </div>
    @endif

    {{-- Step 4: Agent --}}
    @if ($step === 4)
        <div class="flex flex-col gap-1">
            <flux:heading size="lg">{{ __('Set up your AI agent') }}</flux:heading>
            <flux:subheading>{{ __('Agents help you write and organise content. You can add more later.') }}</flux:subheading>
        </div>

        <div class="flex flex-col gap-4">
            <flux:input
                wire:model="agentName"
                :label="__('Agent name')"
                required
            />

            <flux:select wire:model.live="agentProvider" :label="__('Provider')">
                @foreach ($this->availableProviders as $provider)
                    <flux:select.option :value="$provider['value']">{{ $provider['label'] }}</flux:select.option>
                @endforeach
            </flux:select>

            @php $providerEnum = $agentProvider ? App\Enums\Provider::tryFrom($agentProvider) : null; @endphp
            @if ($providerEnum)
                <flux:select wire:model="agentModel" :label="__('Model')" required>
                    @foreach ($providerEnum->models() as $model)
                        <flux:select.option :value="$model">{{ $model }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:textarea
                wire:model="agentPrompt"
                :label="__('System prompt')"
                rows="5"
                required
            />
        </div>

        <div class="flex items-center justify-between">
            <flux:button wire:click="back" variant="ghost">{{ __('Back') }}</flux:button>
            <div class="flex gap-2">
                <flux:button wire:click="skip" variant="ghost">{{ __('Skip setup') }}</flux:button>
                <flux:button wire:click="complete" variant="primary">{{ __('Finish setup') }}</flux:button>
            </div>
        </div>
    @endif

</div>
