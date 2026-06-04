<?php

use App\Enums\MessageStatus;
use App\Enums\Provider;
use App\Enums\ReasoningEffort;
use App\Models\Agent;
use App\Models\Message;
use App\Models\Topic;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Topic')] class extends Component {
    public Topic $topic;

    public string $topicName = '';

    public string $agentName = '';

    public string $agentProvider = '';

    public string $agentModel = '';

    public string $agentReasoningEffort = '';

    public string $agentPrompt = '';

    public bool $showArchived = false;

    public ?int $assignAgentId = null;

    public function mount(Topic $topic): void
    {
        abort_unless(
            Auth::user()->currentWorkspace?->id === $topic->workspace_id,
            403
        );

        $this->topicName = $topic->name;
    }

    public function saveName(): void
    {
        $validated = $this->validate([
            'topicName' => ['required', 'string', 'max:255'],
        ]);

        $this->topic->update(['name' => $validated['topicName']]);

        $this->dispatch('name-saved');

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    /**
     * @return list<array{href: string, name: string, badge: array{label: string, color: string}}>
     */
    public function items(): array
    {
        return $this->topic->messages()
            ->when(! $this->showArchived, fn ($q) => $q->where('status', '!=', MessageStatus::Archived))
            ->get()
            ->map(fn (Message $message) => [
                'href' => route('messages.show', ['topic' => $this->topic->slug, 'message' => $message->slug]),
                'name' => $message->title,
                'badge' => [
                    'label' => $message->status->label(),
                    'color' => $message->status->color(),
                ],
            ])
            ->all();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Agent>
     */
    public function availableAgents(): \Illuminate\Database\Eloquent\Collection
    {
        $assigned = $this->topic->agents()->pluck('agents.id');

        return Auth::user()->currentWorkspace
            ->agents()
            ->whereNotIn('id', $assigned)
            ->get();
    }

    /** @return list<string> */
    #[Computed]
    public function availableAgentModels(): array
    {
        if (! $this->agentProvider) {
            return [];
        }

        $provider = Provider::tryFrom($this->agentProvider);

        return $provider ? $provider->models() : [];
    }

    #[Computed]
    public function showAgentReasoningEffort(): bool
    {
        if (! $this->agentProvider) {
            return false;
        }

        $provider = Provider::tryFrom($this->agentProvider);

        return $provider?->supportsReasoningEffort() ?? false;
    }

    public function updatedAgentProvider(): void
    {
        $this->agentModel = '';
        $this->agentReasoningEffort = '';
    }

    public function createAgent(): void
    {
        $workspace = Auth::user()->currentWorkspace;

        abort_unless($workspace, 403);

        $validated = $this->validate([
            'agentName' => ['required', 'string', 'max:255'],
            'agentProvider' => ['required', 'string', 'in:'.implode(',', array_column(Provider::cases(), 'value'))],
            'agentModel' => ['required', 'string', 'max:255'],
            'agentReasoningEffort' => ['nullable', 'string', 'in:'.implode(',', array_column(ReasoningEffort::cases(), 'value'))],
            'agentPrompt' => ['nullable', 'string'],
        ]);

        $agent = $workspace->agents()->create(['name' => $validated['agentName']]);

        $agent->versions()->create([
            'provider' => $validated['agentProvider'],
            'model' => $validated['agentModel'],
            'reasoning_effort' => $validated['agentReasoningEffort'] ?: null,
            'prompt' => $validated['agentPrompt'] ?: null,
        ]);

        $this->topic->agents()->syncWithoutDetaching($agent);

        $this->reset('agentName', 'agentProvider', 'agentModel', 'agentReasoningEffort', 'agentPrompt');

        Flux::modal('new-agent-for-topic')->close();

        Flux::toast(variant: 'success', text: __('Agent created and assigned.'));
    }

    public function assignAgent(): void
    {
        abort_unless($this->assignAgentId, 422);

        $agent = Auth::user()->currentWorkspace
            ->agents()
            ->findOrFail($this->assignAgentId);

        $this->topic->agents()->syncWithoutDetaching($agent);

        $this->reset('assignAgentId');

        Flux::toast(variant: 'success', text: __('Agent assigned.'));
    }

    public function unassignAgent(int $agentId): void
    {
        $this->topic->agents()->detach($agentId);

        Flux::toast(variant: 'success', text: __('Agent removed.'));
    }

}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-4">
    @include('partials.folder-view', [
        'breadcrumbs' => [
            ['label' => Auth::user()->currentWorkspace?->name, 'href' => route('dashboard')],
            ['label' => $topic->name],
        ],
        'items' => collect($this->items()),
        'icon' => 'document-text',
        'iconClass' => 'size-12 text-neutral-400 group-hover:text-neutral-300',
        'emptyText' => __('No messages'),
        'createHref' => route('messages.create', ['topic' => $topic->slug]),
        'createLabel' => __('New message'),
        'showArchivedModel' => 'showArchived',
        'editNameModel' => 'topicName',
        'editNameAction' => 'saveName',
        'editNameDispatch' => 'name-saved',
    ])

    {{-- Agents --}}
    <div class="flex flex-col gap-2 border-t border-neutral-100 pt-4 dark:border-white/5">
        <flux:heading size="sm">{{ __('Agents') }}</flux:heading>

        @php $workspaceHasAgents = Auth::user()->currentWorkspace->agents()->exists(); @endphp

        @if (!$workspaceHasAgents)
            <div class="flex items-center gap-2">
                <flux:text class="text-sm text-neutral-400 dark:text-neutral-600">{{ __('No agents in this workspace.') }}</flux:text>
                <flux:modal.trigger name="new-agent-for-topic">
                    <flux:button size="xs" icon="plus">{{ __('Create agent') }}</flux:button>
                </flux:modal.trigger>
            </div>
        @else
            <div class="flex flex-wrap items-center gap-2">
                @foreach ($topic->agents as $agent)
                    <div class="flex items-center gap-1 rounded-full border border-neutral-200 bg-neutral-50 py-0.5 pl-3 pr-1 text-sm dark:border-white/10 dark:bg-white/5">
                        <flux:icon name="cpu-chip" class="size-3.5 text-neutral-400" />
                        <span class="text-neutral-700 dark:text-neutral-300">{{ $agent->name }}</span>
                        <flux:button
                            wire:click="unassignAgent({{ $agent->id }})"
                            icon="x-mark"
                            variant="ghost"
                            size="xs"
                            class="size-5 text-neutral-400 hover:text-red-500"
                        />
                    </div>
                @endforeach

                @if ($this->availableAgents()->isNotEmpty())
                    <form wire:submit="assignAgent" class="flex items-center gap-2">
                        <flux:select wire:model="assignAgentId" size="sm" class="w-40" placeholder="{{ __('Assign agent…') }}">
                            @foreach ($this->availableAgents() as $agent)
                                <flux:select.option :value="$agent->id">{{ $agent->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:button type="submit" size="sm" icon="plus" :disabled="!$assignAgentId" />
                    </form>
                @endif
            </div>
        @endif
    </div>

    <flux:modal name="new-agent-for-topic" focusable class="max-w-sm">
        <form wire:submit="createAgent" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('New agent') }}</flux:heading>
                <flux:subheading>{{ __('Create an agent, save its first version, and assign it to this topic.') }}</flux:subheading>
            </div>

            <flux:input wire:model="agentName" :label="__('Name')" type="text" required autofocus />

            <div class="grid grid-cols-2 gap-4">
                <flux:select wire:model.live="agentProvider" :label="__('Provider')" placeholder="{{ __('Select provider…') }}" required>
                    @foreach (Provider::cases() as $providerOption)
                        <flux:select.option :value="$providerOption->value">{{ $providerOption->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="agentModel" :label="__('Model')" placeholder="{{ __('Select model…') }}" :disabled="!$agentProvider" required>
                    @foreach ($this->availableAgentModels as $availableModel)
                        <flux:select.option :value="$availableModel">{{ $availableModel }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @if ($this->showAgentReasoningEffort)
                <flux:select wire:model="agentReasoningEffort" :label="__('Reasoning effort')" placeholder="{{ __('Select effort…') }}">
                    <flux:select.option value="">{{ __('None') }}</flux:select.option>
                    @foreach (ReasoningEffort::cases() as $effort)
                        <flux:select.option :value="$effort->value">{{ $effort->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:textarea wire:model="agentPrompt" :label="__('Prompt')" rows="8" :placeholder="__('System prompt…')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>

</div>
