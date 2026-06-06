<?php

use App\Enums\MessageStatus;
use App\Enums\Provider;
use App\Enums\ReasoningEffort;
use App\Models\Agent;
use App\Models\Message;
use App\Models\Topic;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::workspace'), Title('Dashboard')] class extends Component {
    use WithFileUploads;

    #[Url(as: 'topic')]
    public ?string $selectedTopicSlug = null;

    #[Url(as: 'message')]
    public ?string $selectedMessageSlug = null;

    #[Url(as: 'action')]
    public ?string $panelAction = null;

    #[Url(as: 'panel')]
    public string $mobilePanel = 'topics';

    public string $topicName = '';

    public string $agentName = '';

    public string $provider = '';

    public string $model = '';

    public string $reasoningEffort = '';

    public string $prompt = '';

    public bool $showArchived = false;

    public string $messageTitle = '';

    public string $messageBody = '';

    public string $newMessageTitle = '';

    public string $newMessageBody = '';

    public ?int $newMessageTopicId = null;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $newMessageUploads = [];

    public function mount(): void
    {
        $this->normalizeMobilePanel();
        $this->syncSelectedMessageFields();
        $this->syncNewMessageTopic();
    }

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

    public function selectedMessage(): ?Message
    {
        $topic = $this->selectedTopic();

        if (! $topic || ! $this->selectedMessageSlug) {
            return null;
        }

        return $topic->messages()->where('slug', $this->selectedMessageSlug)->first();
    }

    public function isCreatingMessage(): bool
    {
        return $this->panelAction === 'new-message';
    }

    /**
     * @return list<int>
     */
    public function assignedAgentIds(): array
    {
        $topic = $this->selectedTopic();

        if (! $topic) {
            return [];
        }

        return $topic->agents()->pluck('agents.id')->all();
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
                'href' => route('dashboard', ['topic' => $topic->slug, 'message' => $message->slug, 'panel' => 'messages']),
                'name' => $message->title,
                'badge' => [
                    'label' => $message->status->label(),
                    'color' => $message->status->color(),
                ],
            ])
            ->all();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Topic>
     */
    #[Computed]
    public function availableTopics(): \Illuminate\Database\Eloquent\Collection
    {
        $workspace = $this->workspace();

        if (! $workspace) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return $workspace->topics()->get();
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

    public function updatedMobilePanel(): void
    {
        $this->normalizeMobilePanel();
    }

    public function updatedSelectedTopicSlug(): void
    {
        $this->selectedMessageSlug = null;
        $this->messageTitle = '';
        $this->messageBody = '';
        $this->syncNewMessageTopic();
        $this->normalizeMobilePanel();
    }

    public function updatedSelectedMessageSlug(): void
    {
        if ($this->selectedMessageSlug) {
            $this->panelAction = null;
        }

        $this->syncSelectedMessageFields();
    }

    public function updatedPanelAction(): void
    {
        if ($this->isCreatingMessage()) {
            $this->selectedMessageSlug = null;
            $this->syncNewMessageTopic();
        }
    }

    public function showMobilePanel(string $panel): void
    {
        $this->mobilePanel = $panel;
        $this->normalizeMobilePanel();
    }

    private function normalizeMobilePanel(): void
    {
        if (! in_array($this->mobilePanel, ['topics', 'messages', 'agents'], true)) {
            $this->mobilePanel = 'topics';
        }

        if (! $this->selectedTopic() && $this->mobilePanel === 'messages') {
            $this->mobilePanel = 'topics';
        }
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

    public function createDashboardMessage(): void
    {
        $workspace = $this->workspace();

        abort_unless($workspace, 403);

        $validated = $this->validate([
            'newMessageTitle' => ['required', 'string', 'max:255'],
            'newMessageBody' => ['nullable', 'string'],
            'newMessageTopicId' => ['required', 'integer'],
            'newMessageUploads.*' => ['file', 'max:51200'],
        ]);

        $topic = $workspace->topics()->findOrFail($validated['newMessageTopicId']);

        $message = $topic->messages()->create([
            'title' => $validated['newMessageTitle'],
            'body' => $validated['newMessageBody'] ?: null,
        ]);

        foreach ($this->newMessageUploads as $upload) {
            $filename = $upload->getClientOriginalName();
            $path = $upload->storeAs(
                'attachments/'.Str::uuid(),
                $filename,
                'public'
            );

            $message->attachments()->create([
                'filename' => $filename,
                'path' => $path,
                'mime_type' => $upload->getMimeType(),
                'size' => $upload->getSize(),
            ]);
        }

        $this->selectedTopicSlug = $topic->slug;
        $this->selectedMessageSlug = $message->slug;
        $this->panelAction = null;
        $this->mobilePanel = 'messages';
        $this->reset('newMessageTitle', 'newMessageBody', 'newMessageUploads');
        $this->newMessageTopicId = $topic->id;
        $this->syncSelectedMessageFields();

        Flux::toast(variant: 'success', text: __('Message created.'));
    }

    public function assignAgent(int $agentId): void
    {
        $topic = $this->selectedTopic();

        abort_unless($topic, 422);

        $agent = Auth::user()->currentWorkspace
            ->agents()
            ->findOrFail($agentId);

        $topic->agents()->syncWithoutDetaching($agent);

        Flux::toast(variant: 'success', text: __('Agent assigned.'));
    }

    public function unassignAgent(int $agentId): void
    {
        $topic = $this->selectedTopic();

        abort_unless($topic, 422);

        $topic->agents()->detach($agentId);

        Flux::toast(variant: 'success', text: __('Agent removed.'));
    }

    public function saveSelectedMessage(): void
    {
        $message = $this->selectedMessage();

        abort_unless($message && $message->status === MessageStatus::Draft, 403);

        $validated = $this->validate([
            'messageTitle' => ['required', 'string', 'max:255'],
            'messageBody' => ['nullable', 'string'],
        ]);

        $message->update([
            'title' => $validated['messageTitle'],
            'body' => $validated['messageBody'],
        ]);

        $this->selectedMessageSlug = $message->fresh()->slug;

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function publishSelectedMessage(): void
    {
        $message = $this->selectedMessage();

        abort_unless($message && $message->status === MessageStatus::Draft, 403);

        $this->saveSelectedMessage();

        $message->fresh()->update(['status' => MessageStatus::Published]);
    }

    public function archiveSelectedMessage(): void
    {
        $message = $this->selectedMessage();

        abort_unless($message, 404);

        $message->update(['status' => MessageStatus::Archived]);
    }

    public function unpublishSelectedMessage(): void
    {
        $message = $this->selectedMessage();

        abort_unless($message && $message->status === MessageStatus::Published, 403);

        $message->update(['status' => MessageStatus::Draft]);

        $this->syncSelectedMessageFields();
    }

    public function unarchiveSelectedMessage(): void
    {
        $message = $this->selectedMessage();

        abort_unless($message && $message->status === MessageStatus::Archived, 403);

        $message->update(['status' => MessageStatus::Draft]);

        $this->syncSelectedMessageFields();
    }

    private function syncSelectedMessageFields(): void
    {
        $message = $this->selectedMessage();

        $this->messageTitle = $message?->title ?? '';
        $this->messageBody = $message?->body ?? '';
    }

    private function syncNewMessageTopic(): void
    {
        $topic = $this->selectedTopic();

        if ($topic) {
            $this->newMessageTopicId = $topic->id;
        }
    }
}; ?>

@php
    $mobilePanelMinHeight = 'min-h-[calc(100dvh-4rem)]';
@endphp

<div class="flex h-full w-full flex-col gap-3 xl:flex-1">
    @if ($this->workspace())
        @php
            $hasSelectedTopic = (bool) $this->selectedTopic();
        @endphp

        <div class="grid grid-cols-1 items-stretch gap-3 xl:flex-1 xl:auto-rows-fr xl:grid-cols-[16rem_minmax(0,1fr)_19rem]">
            <section
                id="topics-panel"
                data-mobile-panel="topics"
                @class([
                    "scroll-mt-4 {$mobilePanelMinHeight} flex flex-col overflow-hidden rounded-xl border border-neutral-300 bg-white shadow-sm shadow-black/[0.04] xl:h-full xl:min-h-[24rem] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none",
                    'hidden xl:flex' => $this->mobilePanel !== 'topics',
                ])
            >
                <div class="flex items-center justify-between gap-3 border-b border-neutral-300 bg-blue-50 px-4 py-3 dark:border-white/10 dark:bg-blue-500/10">
                    <flux:heading size="sm">{{ __('Topics') }}</flux:heading>
                    <flux:modal.trigger name="new-topic">
                        <flux:button icon="plus" size="xs">{{ __('New topic') }}</flux:button>
                    </flux:modal.trigger>
                </div>

                @if ($this->topics()->isEmpty())
                    <div class="bg-white px-4 py-6 xl:flex xl:flex-1 xl:items-start dark:bg-zinc-900/20">
                        <flux:text class="text-sm text-neutral-400 dark:text-neutral-600">{{ __('No topics') }}</flux:text>
                    </div>
                @else
                    <div class="divide-y divide-neutral-200 bg-white xl:flex-1 xl:overflow-auto dark:divide-white/5 dark:bg-zinc-900/20">
                        @foreach ($this->topics() as $topic)
                            <a href="{{ route('dashboard', ['topic' => $topic->slug, 'panel' => 'messages']) }}" wire:navigate
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
                                        <flux:badge color="zinc" size="sm" title="{{ __('Draft messages') }}" data-test="topic-{{ $topic->slug }}-draft-count" data-count="{{ $topic->draft_count }}">{{ $topic->draft_count }}</flux:badge>
                                    @endif
                                    @if ($topic->published_count > 0)
                                        <flux:badge color="green" size="sm" title="{{ __('Published messages') }}" data-test="topic-{{ $topic->slug }}-published-count" data-count="{{ $topic->published_count }}">{{ $topic->published_count }}</flux:badge>
                                    @endif
                                    @if ($showArchived && $selectedTopicSlug === $topic->slug && $topic->archived_count > 0)
                                        <flux:badge color="yellow" size="sm" title="{{ __('Archived messages') }}" data-test="topic-{{ $topic->slug }}-archived-count" data-count="{{ $topic->archived_count }}">{{ $topic->archived_count }}</flux:badge>
                                    @endif
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>

            @if ($this->selectedTopic())
                @php $selectedDashboardMessage = $this->selectedMessage(); @endphp

                <section
                    id="messages-panel"
                    data-mobile-panel="messages"
                    @class([
                        "scroll-mt-4 {$mobilePanelMinHeight} flex flex-col overflow-hidden rounded-xl border border-neutral-300 bg-white shadow-sm shadow-black/[0.04] xl:h-full xl:min-h-[24rem] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none",
                        'hidden xl:flex' => $this->mobilePanel !== 'messages',
                    ])
                >
                    @if ($this->isCreatingMessage())
                        <div class="flex items-center justify-between gap-3 border-b border-neutral-300 bg-emerald-50 px-4 py-3 dark:border-white/10 dark:bg-emerald-500/10">
                            <flux:heading size="sm" class="min-w-0 flex-1 truncate">{{ __('New message') }}</flux:heading>

                            <flux:button :href="route('dashboard', ['topic' => $this->selectedTopic()->slug, 'panel' => 'messages'])" wire:navigate size="xs" variant="filled" icon="arrow-left">
                                {{ __('Messages') }}
                            </flux:button>
                        </div>

                        <div class="flex flex-1 flex-col gap-6 overflow-auto px-4 py-4 xl:min-h-0" data-test="dashboard-message-create-panel">
                            <form wire:submit="createDashboardMessage" class="flex flex-col gap-6">
                                <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_16rem]">
                                    <flux:input wire:model="newMessageTitle" :label="__('Title')" required autofocus />

                                    <flux:select wire:model="newMessageTopicId" :label="__('Topic')" placeholder="{{ __('Select a topic…') }}" required>
                                        @foreach ($this->availableTopics as $topic)
                                            <flux:select.option :value="$topic->id">{{ $topic->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </div>

                                <flux:textarea wire:model="newMessageBody" :label="__('Body')" :placeholder="__('Write something...')" rows="12" />

                                <div class="flex flex-col gap-3">
                                    <flux:heading size="sm">{{ __('Attachments') }}</flux:heading>

                                    <div x-data="{ uploading: false, progress: 0 }"
                                         x-on:livewire-upload-start="uploading = true"
                                         x-on:livewire-upload-finish="uploading = false"
                                         x-on:livewire-upload-error="uploading = false"
                                         x-on:livewire-upload-progress="progress = $event.detail.progress"
                                         class="flex flex-col gap-2">
                                        <flux:input type="file" wire:model="newMessageUploads" multiple />

                                        <div x-show="uploading" class="h-1 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-white/10">
                                            <div class="h-full rounded-full bg-blue-500 transition-all" :style="`width: ${progress}%`"></div>
                                        </div>

                                        @error('newMessageUploads.*')
                                            <flux:error>{{ $message }}</flux:error>
                                        @enderror
                                    </div>
                                </div>

                                <div class="flex justify-end gap-2">
                                    <flux:button :href="route('dashboard', ['topic' => $this->selectedTopic()->slug, 'panel' => 'messages'])" wire:navigate variant="filled">
                                        {{ __('Cancel') }}
                                    </flux:button>
                                    <flux:button type="submit" variant="primary">{{ __('Create draft') }}</flux:button>
                                </div>
                            </form>
                        </div>
                    @elseif ($selectedDashboardMessage)
                        <div class="flex items-center justify-between gap-3 border-b border-neutral-300 bg-emerald-50 px-4 py-3 dark:border-white/10 dark:bg-emerald-500/10">
                            <flux:heading size="sm" class="min-w-0 flex-1 truncate">{{ $selectedDashboardMessage->title }}</flux:heading>

                            <flux:button :href="route('dashboard', ['topic' => $this->selectedTopic()->slug, 'panel' => 'messages'])" wire:navigate size="xs" variant="filled" icon="arrow-left">
                                {{ __('Messages') }}
                            </flux:button>
                        </div>

                        <div class="flex flex-1 flex-col gap-6 overflow-auto px-4 py-4 xl:min-h-0" data-test="dashboard-message-panel">
                            @if ($selectedDashboardMessage->status === MessageStatus::Draft)
                                <form wire:submit="saveSelectedMessage" class="flex flex-col gap-4">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <flux:input wire:model="messageTitle" class="flex-1" required />

                                        <div class="flex shrink-0 items-center gap-2">
                                            <flux:badge :color="$selectedDashboardMessage->status->color()" size="sm">{{ $selectedDashboardMessage->status->label() }}</flux:badge>
                                            <flux:button wire:click="archiveSelectedMessage" type="button" size="sm" icon="archive-box">{{ __('Archive') }}</flux:button>
                                            <flux:button wire:click="publishSelectedMessage" type="button" size="sm" variant="primary" icon="arrow-up-circle">{{ __('Publish') }}</flux:button>
                                        </div>
                                    </div>

                                    <flux:textarea wire:model="messageBody" :placeholder="__('Write something...')" rows="12" />

                                    <div class="flex justify-end">
                                        <flux:button type="submit" size="sm" variant="filled">{{ __('Save draft') }}</flux:button>
                                    </div>
                                </form>
                            @else
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <flux:heading size="xl" class="min-w-0 flex-1 truncate">{{ $selectedDashboardMessage->title }}</flux:heading>

                                    <div class="flex shrink-0 items-center gap-2">
                                        <flux:badge :color="$selectedDashboardMessage->status->color()" size="sm">{{ $selectedDashboardMessage->status->label() }}</flux:badge>

                                        @if ($selectedDashboardMessage->status === MessageStatus::Published)
                                            <flux:button wire:click="unpublishSelectedMessage" size="sm" icon="arrow-down-circle">{{ __('Unpublish') }}</flux:button>
                                            <flux:button wire:click="archiveSelectedMessage" size="sm" icon="archive-box">{{ __('Archive') }}</flux:button>
                                        @elseif ($selectedDashboardMessage->status === MessageStatus::Archived)
                                            <flux:button wire:click="unarchiveSelectedMessage" size="sm" icon="archive-box-x-mark">{{ __('Unarchive') }}</flux:button>
                                        @endif
                                    </div>
                                </div>

                                <div>
                                    @if ($selectedDashboardMessage->body)
                                        <flux:text class="whitespace-pre-wrap text-sm leading-relaxed text-neutral-700 dark:text-neutral-300">{{ $selectedDashboardMessage->body }}</flux:text>
                                    @else
                                        <flux:text class="text-sm text-neutral-400 dark:text-neutral-600">{{ __('No content.') }}</flux:text>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @else
                        @include('partials.folder-view', [
                            'breadcrumbs' => [
                                ['label' => $this->workspace()->name, 'href' => route('dashboard')],
                                ['label' => $this->selectedTopic()->name],
                            ],
                            'titleLabel' => __('Messages'),
                            'items' => collect($this->selectedTopicItems()),
                            'icon' => 'document-text',
                            'iconClass' => 'size-12 text-neutral-400 group-hover:text-neutral-300',
                            'emptyText' => __('No messages'),
                            'createHref' => route('dashboard', ['topic' => $this->selectedTopic()->slug, 'action' => 'new-message', 'panel' => 'messages']),
                            'createLabel' => __('New message'),
                            'showArchivedModel' => 'showArchived',
                            'toolbarClass' => 'border-b border-neutral-300 bg-emerald-50 px-4 py-3 dark:border-white/10 dark:bg-emerald-500/10',
                            'rootClass' => 'flex flex-col xl:h-full',
                            'contentClass' => 'overflow-auto px-4 py-4 xl:flex-1 xl:min-h-0',
                        ])
                    @endif
                </section>
            @else
                <section
                    id="messages-panel"
                    data-mobile-panel="messages"
                    @class([
                        "scroll-mt-4 {$mobilePanelMinHeight} flex flex-col overflow-hidden rounded-xl border border-neutral-300 bg-white shadow-sm shadow-black/[0.04] xl:h-full xl:min-h-[24rem] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none",
                        'hidden xl:flex' => $this->mobilePanel !== 'messages',
                    ])
                >
                    <div class="flex items-center justify-between gap-3 border-b border-neutral-300 bg-emerald-50 px-4 py-3 dark:border-white/10 dark:bg-emerald-500/10">
                        <flux:heading size="sm">{{ __('Messages') }}</flux:heading>
                    </div>

                    <div class="flex flex-1 items-center justify-center px-6 py-10 text-center">
                        <div class="space-y-2">
                            <flux:heading size="sm">{{ __('Select a topic') }}</flux:heading>
                            <flux:text class="text-sm text-neutral-400 dark:text-neutral-600">
                                {{ __('Choose a topic to view its messages.') }}
                            </flux:text>
                        </div>
                    </div>
                </section>
            @endif

            <div
                data-mobile-panel="agents"
                @class([
                    'xl:block',
                    'hidden xl:block' => $this->mobilePanel !== 'agents',
                ])
            >
                @include('partials.workspace-agents-rail', [
                    'agents' => $this->agents(),
                    'createModal' => 'new-dashboard-agent',
                    'panelId' => 'agents-panel',
                    'asideClass' => 'xl:h-full',
                    'containerClass' => $mobilePanelMinHeight,
                    'sticky' => false,
                    'assignedAgentIds' => $this->selectedTopic() ? $this->assignedAgentIds() : [],
                    'assignAction' => $this->selectedTopic() ? 'assignAgent' : null,
                    'unassignAction' => $this->selectedTopic() ? 'unassignAgent' : null,
                ])
            </div>
        </div>

        <nav class="fixed inset-x-0 bottom-0 z-40 bg-white/95 px-2 py-2 backdrop-blur xl:hidden dark:bg-zinc-900/95">
            <div class="grid grid-cols-3 gap-2">
                <button
                    type="button"
                    wire:click="showMobilePanel('topics')"
                    data-mobile-nav="topics"
                    aria-pressed="{{ $this->mobilePanel === 'topics' ? 'true' : 'false' }}"
                    @class([
                        'flex items-center justify-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition',
                        'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-400/30 dark:bg-blue-500/15 dark:text-blue-200' => $this->mobilePanel === 'topics',
                        'border-neutral-200 bg-neutral-50 text-neutral-700 dark:border-white/10 dark:bg-white/5 dark:text-neutral-200' => $this->mobilePanel !== 'topics',
                    ])
                >
                    <flux:icon name="hashtag" class="size-4" />
                    <span>{{ __('Topics') }}</span>
                </button>
                <button
                    type="button"
                    @if ($hasSelectedTopic) wire:click="showMobilePanel('messages')" @endif
                    data-mobile-nav="messages"
                    aria-pressed="{{ $this->mobilePanel === 'messages' ? 'true' : 'false' }}"
                    @disabled(! $hasSelectedTopic)
                    @class([
                        'flex items-center justify-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition',
                        'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-400/30 dark:bg-emerald-500/15 dark:text-emerald-200' => $hasSelectedTopic && $this->mobilePanel === 'messages',
                        'border-neutral-200 bg-neutral-50 text-neutral-700 dark:border-white/10 dark:bg-white/5 dark:text-neutral-200' => $hasSelectedTopic && $this->mobilePanel !== 'messages',
                        'cursor-not-allowed border-neutral-200 bg-neutral-50 text-neutral-300 opacity-60 dark:border-white/10 dark:bg-white/5 dark:text-neutral-600' => ! $hasSelectedTopic,
                    ])
                >
                    <flux:icon name="document-text" class="size-4" />
                    <span>{{ __('Messages') }}</span>
                </button>
                <button
                    type="button"
                    wire:click="showMobilePanel('agents')"
                    data-mobile-nav="agents"
                    aria-pressed="{{ $this->mobilePanel === 'agents' ? 'true' : 'false' }}"
                    @class([
                        'flex items-center justify-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition',
                        'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-400/30 dark:bg-amber-500/15 dark:text-amber-200' => $this->mobilePanel === 'agents',
                        'border-neutral-200 bg-neutral-50 text-neutral-700 dark:border-white/10 dark:bg-white/5 dark:text-neutral-200' => $this->mobilePanel !== 'agents',
                    ])
                >
                    <flux:icon name="cpu-chip" class="size-4" />
                    <span>{{ __('Agents') }}</span>
                </button>
            </div>
        </nav>

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
