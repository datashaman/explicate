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
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::workspace'), Title('Dashboard')] class extends Component {
    #[Url(as: 'topic')]
    public ?string $selectedTopicSlug = null;

    public string $topicName = '';

    public string $agentName = '';

    public string $provider = '';

    public string $model = '';

    public string $reasoningEffort = '';

    public string $prompt = '';

    public bool $showArchived = false;

    public function workspace(): ?\App\Models\Workspace
    {
        return Auth::user()->currentWorkspace;
    }

    public function selectedTopic(): ?Topic
    {
        $workspace = $this->workspace();

        if (! $workspace || ! $this->selectedTopicSlug) {
            return null;
        }

        return $workspace->topics()->where('slug', $this->selectedTopicSlug)->first();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Agent>
     */
    public function agents(): \Illuminate\Database\Eloquent\Collection
    {
        $workspace = $this->workspace();

        if (! $workspace) {
            return Agent::query()->whereNull('id')->get();
        }

        return $workspace->agents()->with('latestVersion')->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Topic>
     */
    public function topics(): \Illuminate\Database\Eloquent\Collection
    {
        $workspace = $this->workspace();

        if (! $workspace) {
            return Topic::query()->whereNull('id')->get();
        }

        return $workspace->topics()
            ->withCount([
                'messages as draft_count' => fn ($q) => $q->where('status', MessageStatus::Draft),
                'messages as published_count' => fn ($q) => $q->where('status', MessageStatus::Published),
                'messages as archived_count' => fn ($q) => $q->where('status', MessageStatus::Archived),
            ])
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Message>
     */
    public function workspaceMessages(): \Illuminate\Database\Eloquent\Collection
    {
        $workspace = $this->workspace();

        if (! $workspace) {
            return Message::query()->whereNull('id')->get();
        }

        return Message::query()
            ->with('topic')
            ->whereHas('topic', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->when(! $this->showArchived, fn ($query) => $query->where('status', '!=', MessageStatus::Archived))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return list<array{href: string, name: string, badge: array{label: string, color: string}}>
     */
    public function selectedTopicItems(): array
    {
        $topic = $this->selectedTopic();

        if (! $topic) {
            return [];
        }

        return $topic->messages()
            ->when(! $this->showArchived, fn ($query) => $query->where('status', '!=', MessageStatus::Archived))
            ->get()
            ->map(fn (Message $message) => [
                'href' => route('messages.show', ['topic' => $topic->slug, 'message' => $message->slug]),
                'name' => $message->title,
                'badge' => [
                    'label' => $message->status->label(),
                    'color' => $message->status->color(),
                ],
            ])
            ->all();
    }

    /** @return list<string> */
    #[Computed]
    public function availableModels(): array
    {
        if (! $this->provider) {
            return [];
        }

        $provider = Provider::tryFrom($this->provider);

        return $provider ? $provider->models() : [];
    }

    #[Computed]
    public function showReasoningEffort(): bool
    {
        if (! $this->provider) {
            return false;
        }

        $provider = Provider::tryFrom($this->provider);

        return $provider?->supportsReasoningEffort() ?? false;
    }

    public function updatedProvider(): void
    {
        $this->model = '';
        $this->reasoningEffort = '';
    }

    public function createTopic(): void
    {
        $workspace = $this->workspace();

        abort_unless($workspace, 403);

        $validated = $this->validate([
            'topicName' => ['required', 'string', 'max:255'],
        ]);

        $workspace->topics()->create(['name' => $validated['topicName']]);

        $this->reset('topicName');

        Flux::modal('new-topic')->close();

        Flux::toast(variant: 'success', text: __('Topic created.'));
    }

    public function createAgent(): void
    {
        $workspace = $this->workspace();

        abort_unless($workspace, 403);

        $validated = $this->validate([
            'agentName' => ['required', 'string', 'max:255'],
            'provider' => ['required', 'string', 'in:'.implode(',', array_column(Provider::cases(), 'value'))],
            'model' => ['required', 'string', 'max:255'],
            'reasoningEffort' => ['nullable', 'string', 'in:'.implode(',', array_column(ReasoningEffort::cases(), 'value'))],
            'prompt' => ['nullable', 'string'],
        ]);

        $agent = $workspace->agents()->create(['name' => $validated['agentName']]);

        $agent->versions()->create([
            'provider' => $validated['provider'],
            'model' => $validated['model'],
            'reasoning_effort' => $validated['reasoningEffort'] ?: null,
            'prompt' => $validated['prompt'] ?: null,
        ]);

        $this->reset('agentName', 'provider', 'model', 'reasoningEffort', 'prompt');

        Flux::modal('new-dashboard-agent')->close();

        Flux::toast(variant: 'success', text: __('Agent created.'));
    }
}; ?>

<div class="flex h-full w-full flex-col gap-3 xl:flex-1">
    @if ($this->workspace())
        <div class="grid grid-cols-1 items-stretch gap-3 xl:flex-1 xl:auto-rows-fr xl:grid-cols-[16rem_minmax(0,1fr)_19rem]">
            <section class="flex flex-col overflow-hidden rounded-xl border border-neutral-300 bg-white shadow-sm shadow-black/[0.04] xl:h-full xl:min-h-[24rem] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none">
                <div class="flex items-center justify-between gap-3 border-b border-neutral-300 bg-blue-50 px-4 py-3 dark:border-white/10 dark:bg-blue-500/10">
                    <flux:heading size="sm">{{ __('Topics') }}</flux:heading>
                    <flux:modal.trigger name="new-topic">
                        <flux:button icon="plus" size="xs">{{ __('New topic') }}</flux:button>
                    </flux:modal.trigger>
                </div>

                @if ($this->topics()->isEmpty())
                    <div class="flex flex-1 items-start bg-white px-4 py-6 dark:bg-zinc-900/20">
                        <flux:text class="text-sm text-neutral-400 dark:text-neutral-600">{{ __('No topics') }}</flux:text>
                    </div>
                @else
                    <div class="flex-1 divide-y divide-neutral-200 overflow-auto bg-white dark:divide-white/5 dark:bg-zinc-900/20">
                        @foreach ($this->topics() as $topic)
                            <a href="{{ route('dashboard', ['topic' => $topic->slug]) }}" wire:navigate
                               @class([
                                   'flex items-center gap-3 px-4 py-3 hover:bg-neutral-100 dark:hover:bg-white/5',
                                   'bg-blue-100/80 dark:bg-blue-500/15' => $selectedTopicSlug === $topic->slug,
                               ])>
                                <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-blue-50 text-blue-500 dark:bg-blue-500/10 dark:text-blue-300">
                                    <flux:icon name="hashtag" class="size-4" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ $topic->name }}</div>
                                </div>
                                <div class="flex shrink-0 items-center gap-1">
                                    @if ($topic->draft_count > 0)
                                        <flux:badge color="zinc" size="sm">{{ $topic->draft_count }}</flux:badge>
                                    @endif
                                    @if ($topic->published_count > 0)
                                        <flux:badge color="green" size="sm">{{ $topic->published_count }}</flux:badge>
                                    @endif
                                    @if ($showArchived && $topic->archived_count > 0)
                                        <flux:badge color="yellow" size="sm">{{ $topic->archived_count }}</flux:badge>
                                    @endif
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>

            @if ($this->selectedTopic())
                <div class="flex flex-col overflow-hidden rounded-xl border border-neutral-300 bg-white p-4 shadow-sm shadow-black/[0.04] xl:h-full xl:min-h-[24rem] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none">
                    @include('partials.folder-view', [
                        'breadcrumbs' => [
                            ['label' => $this->workspace()->name, 'href' => route('dashboard')],
                            ['label' => $this->selectedTopic()->name],
                        ],
                        'items' => collect($this->selectedTopicItems()),
                        'icon' => 'document-text',
                        'iconClass' => 'size-12 text-neutral-400 group-hover:text-neutral-300',
                        'emptyText' => __('No messages'),
                        'createHref' => route('messages.create', ['topic' => $this->selectedTopic()->slug]),
                        'createLabel' => __('New message'),
                        'showArchivedModel' => 'showArchived',
                        'rootClass' => 'flex h-full flex-col',
                        'contentClass' => 'flex-1 min-h-0',
                    ])
                </div>
            @else
                <section class="flex flex-col overflow-hidden rounded-xl border border-neutral-300 bg-white shadow-sm shadow-black/[0.04] xl:h-full xl:min-h-[24rem] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none">
                    <div class="flex items-center justify-between gap-3 border-b border-neutral-300 bg-emerald-50 px-4 py-3 dark:border-white/10 dark:bg-emerald-500/10">
                        <div class="flex items-center gap-3">
                            <flux:heading size="sm">{{ __('Messages') }}</flux:heading>
                            <flux:checkbox wire:model.live="showArchived" :label="__('Show archived')" />
                        </div>

                        <flux:button :href="route('messages.create')" wire:navigate icon="plus" size="xs">{{ __('New message') }}</flux:button>
                    </div>

                    @if ($this->workspaceMessages()->isEmpty())
                        <div class="flex flex-1 items-start bg-white px-4 py-6 dark:bg-zinc-900/20">
                            <flux:text class="text-sm text-neutral-400 dark:text-neutral-600">{{ __('No messages') }}</flux:text>
                        </div>
                    @else
                        <div class="flex-1 divide-y divide-neutral-200 overflow-auto bg-white dark:divide-white/5 dark:bg-zinc-900/20">
                            @foreach ($this->workspaceMessages() as $message)
                                <a href="{{ route('messages.show', ['topic' => $message->topic->slug, 'message' => $message->slug]) }}" wire:navigate
                                   class="flex items-center gap-3 px-4 py-3 hover:bg-neutral-100 dark:hover:bg-white/5">
                                    <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-neutral-500 dark:bg-white/10 dark:text-neutral-300">
                                        <flux:icon name="document-text" class="size-4" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ $message->title }}</div>
                                        <div class="truncate text-xs text-neutral-400 dark:text-neutral-500">{{ $message->topic->name }}</div>
                                    </div>
                                    <flux:badge :color="$message->status->color()" size="sm">{{ $message->status->label() }}</flux:badge>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif

            @include('partials.workspace-agents-rail', [
                'agents' => $this->agents(),
                'createModal' => 'new-dashboard-agent',
            ])
        </div>

        <flux:modal name="new-topic" :show="$errors->isNotEmpty()" focusable class="max-w-sm">
            <form wire:submit="createTopic" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('New topic') }}</flux:heading>
                    <flux:subheading>{{ __('Give your topic a name.') }}</flux:subheading>
                </div>

                <flux:input wire:model="topicName" :label="__('Name')" type="text" required autofocus />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit">{{ __('Create') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="new-dashboard-agent" focusable class="max-w-sm">
            <form wire:submit="createAgent" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('New agent') }}</flux:heading>
                    <flux:subheading>{{ __('Set up your agent and create its first version.') }}</flux:subheading>
                </div>

                <flux:input wire:model="agentName" :label="__('Name')" type="text" required autofocus />

                <div class="grid grid-cols-2 gap-4">
                    <flux:select wire:model.live="provider" :label="__('Provider')" placeholder="{{ __('Select provider…') }}" required>
                        @foreach (Provider::cases() as $providerOption)
                            <flux:select.option :value="$providerOption->value">{{ $providerOption->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="model" :label="__('Model')" placeholder="{{ __('Select model…') }}" :disabled="!$provider" required>
                        @foreach ($this->availableModels as $availableModel)
                            <flux:select.option :value="$availableModel">{{ $availableModel }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                @if ($this->showReasoningEffort)
                    <flux:select wire:model="reasoningEffort" :label="__('Reasoning effort')" placeholder="{{ __('Select effort…') }}">
                        <flux:select.option value="">{{ __('None') }}</flux:select.option>
                        @foreach (ReasoningEffort::cases() as $effort)
                            <flux:select.option :value="$effort->value">{{ $effort->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:textarea wire:model="prompt" :label="__('Prompt')" rows="8" :placeholder="__('System prompt…')" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit">{{ __('Create') }}</flux:button>
                </div>
            </form>
        </flux:modal>

    @else
        <div class="flex flex-1 flex-col items-center justify-center gap-4">
            <div class="text-center">
                <flux:heading>{{ __('No workspace selected') }}</flux:heading>
                <flux:subheading>{{ __('Select or create a workspace to get started.') }}</flux:subheading>
            </div>
            <flux:modal.trigger name="create-workspace-switcher">
                <flux:button variant="primary" icon="plus">{{ __('New workspace') }}</flux:button>
            </flux:modal.trigger>
        </div>
    @endif
</div>
