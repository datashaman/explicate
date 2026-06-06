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
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::workspace'), Title('Topic')] class extends Component {
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

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Agent>
     */
    public function workspaceAgents(): \Illuminate\Database\Eloquent\Collection
    {
        return Auth::user()->currentWorkspace
            ->agents()
            ->with('latestVersion')
            ->get();
    }

    /**
     * @return list<int>
     */
    public function assignedAgentIds(): array
    {
        return $this->topic->agents()->pluck('agents.id')->all();
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

    public function assignAgent(?int $agentId = null): void
    {
        $agentId ??= $this->assignAgentId;

        abort_unless($agentId, 422);

        $agent = Auth::user()->currentWorkspace
            ->agents()
            ->findOrFail($agentId);

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

<div class="flex h-full w-full flex-1 flex-col gap-3 xl:flex-1">
    <div class="grid grid-cols-1 items-stretch gap-3 xl:flex-1 xl:auto-rows-fr xl:grid-cols-[minmax(0,1fr)_19rem]">
        <section class="flex min-h-[calc(100dvh-4rem)] flex-col overflow-hidden rounded-xl border border-neutral-300 bg-white shadow-sm shadow-black/[0.04] xl:h-full xl:min-h-[24rem] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none">
            @include('partials.folder-view', [
                'breadcrumbs' => [
                    ['label' => Auth::user()->currentWorkspace?->name, 'href' => route('dashboard')],
                    ['label' => $topic->name],
                ],
                'titleLabel' => __('Messages'),
                'items' => collect($this->items()),
                'icon' => 'document-text',
                'iconClass' => 'size-12 text-neutral-400 group-hover:text-neutral-300',
                'emptyText' => __('No messages'),
                'createHref' => route('messages.create', ['topic' => $topic->slug]),
                'createLabel' => __('New message'),
                'showArchivedModel' => 'showArchived',
                'toolbarClass' => 'border-b border-neutral-300 bg-emerald-50 px-4 py-3 dark:border-white/10 dark:bg-emerald-500/10',
                'rootClass' => 'flex flex-col xl:h-full',
                'contentClass' => 'overflow-auto px-4 py-4 xl:flex-1 xl:min-h-0',
            ])
        </section>

        @include('partials.workspace-agents-rail', [
            'agents' => $this->workspaceAgents(),
            'createModal' => 'new-agent-for-topic',
            'assignedAgentIds' => $this->assignedAgentIds(),
            'assignAction' => 'assignAgent',
            'unassignAction' => 'unassignAgent',
            'asideClass' => 'xl:h-full',
            'containerClass' => 'min-h-[calc(100dvh-4rem)]',
            'sticky' => false,
        ])
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
