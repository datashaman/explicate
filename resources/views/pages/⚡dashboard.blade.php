<?php

use App\Enums\MessageStatus;
use App\Enums\Provider;
use App\Enums\ReasoningEffort;
use App\Models\Agent;
use App\Models\Message;
use App\Models\Topic;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
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

    #[Url(as: 'folder')]
    public ?string $selectedSystemFolderSlug = null;

    #[Url(as: 'message')]
    public ?string $selectedMessageSlug = null;

    #[Url(as: 'action')]
    public ?string $panelAction = null;

    #[Url(as: 'agent')]
    public ?string $selectedAgentSlug = null;

    #[Url(as: 'panel')]
    public string $mobilePanel = 'topics';

    public bool $creatingMessageFromRoute = false;

    public string $topicName = '';

    public string $agentName = '';

    public string $provider = '';

    public string $model = '';

    public string $reasoningEffort = '';

    public string $prompt = '';

    public string $selectedAgentName = '';

    public string $selectedAgentProvider = '';

    public string $selectedAgentModel = '';

    public string $selectedAgentReasoningEffort = '';

    public string $selectedAgentPrompt = '';

    public bool $showArchived = false;

    public string $messageTitle = '';

    public string $messageBody = '';

    /** @var list<int> */
    public array $messageAgentIds = [];

    public string $newMessageTitle = '';

    public string $newMessageBody = '';

    public ?int $newMessageTopicId = null;

    /** @var list<int> */
    public array $newMessageAgentIds = [];

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $newMessageUploads = [];

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $messageUploads = [];

    public function mount(): void
    {
        if ($this->isCreateRoute()) {
            $this->creatingMessageFromRoute = true;
            $this->mobilePanel = 'messages';
        }

        $this->normalizeMobilePanel();
        $this->syncSelectedMessageFields();
        $this->syncNewMessageTopic();
        $this->syncSelectedAgentFields();
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

    /** @return array{slug: string, name: string, icon: string}|null */
    public function selectedSystemFolder(): ?array
    {
        if (! $this->selectedSystemFolderSlug) {
            return null;
        }

        return collect($this->systemFolders())->firstWhere('slug', $this->selectedSystemFolderSlug);
    }

    public function selectedMessage(): ?Message
    {
        $topic = $this->selectedTopic();

        if (! $topic || ! $this->selectedMessageSlug) {
            return null;
        }

        return $topic->messages()->where('slug', $this->selectedMessageSlug)->first();
    }

    public function selectedAgent(): ?Agent
    {
        $workspace = $this->workspace();

        if (! $workspace || ! $this->selectedAgentSlug) {
            return null;
        }

        return $workspace->agents()
            ->with(['latestVersion', 'versions' => fn ($query) => $query->orderByDesc('version')])
            ->where('slug', $this->selectedAgentSlug)
            ->first();
    }

    public function isCreatingMessage(): bool
    {
        return $this->creatingMessageFromRoute || $this->panelAction === 'new-message';
    }

    /** @return list<array{slug: string, name: string, icon: string, count: int}> */
    public function systemFolders(): array
    {
        $workspace = $this->workspace();

        if (! $workspace) {
            return [];
        }

        $counts = Message::query()
            ->whereHas('topic', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            [
                'slug' => 'updates',
                'name' => __('Updates'),
                'icon' => 'inbox',
                'count' => (int) ($counts[MessageStatus::Published->value] ?? 0),
            ],
            [
                'slug' => 'drafts',
                'name' => __('Drafts'),
                'icon' => 'document',
                'count' => (int) ($counts[MessageStatus::Draft->value] ?? 0),
            ],
            [
                'slug' => 'archived',
                'name' => __('Archived'),
                'icon' => 'archive-box',
                'count' => (int) ($counts[MessageStatus::Archived->value] ?? 0),
            ],
        ];
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
     * @return \Illuminate\Database\Eloquent\Collection<int, Agent>
     */
    public function newMessageAssignableAgents(): \Illuminate\Database\Eloquent\Collection
    {
        $workspace = $this->workspace();

        if (! $workspace || ! $this->newMessageTopicId) {
            return Agent::query()->whereNull('id')->get();
        }

        $topic = $workspace->topics()->find($this->newMessageTopicId);

        if (! $topic) {
            return Agent::query()->whereNull('id')->get();
        }

        return $topic->agents()->with('latestVersion')->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Agent>
     */
    public function selectedMessageAssignableAgents(): \Illuminate\Database\Eloquent\Collection
    {
        $message = $this->selectedMessage();

        if (! $message) {
            return Agent::query()->whereNull('id')->get();
        }

        return $message->topic->agents()->with('latestVersion')->get();
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
     * @return list<array{href: string, name: string, meta: list<array{label: string, value: string}>, attachments_count: int, badge: array{label: string, color: string}|null}>
     */
    public function selectedTopicItems(): array
    {
        $topic = $this->selectedTopic();

        if (! $topic) {
            return [];
        }

        return $topic->messages()
            ->with(['sender.user', 'sender.agent'])
            ->withCount('attachments')
            ->when(! $this->showArchived, fn ($query) => $query->where('status', '!=', MessageStatus::Archived))
            ->where('status', '!=', MessageStatus::Draft)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Message $message) => [
                'href' => route('dashboard', ['topic' => $topic->slug, 'message' => $message->slug, 'panel' => 'messages']),
                'name' => $message->title,
                'meta' => $message->listMeta(showSender: true, showRecipient: false, timezone: Auth::user()->displayTimezone()),
                'attachments_count' => $message->attachments_count,
                'sort' => $message->listSortValues(dateKey: 'sent'),
                'badge' => $message->status === MessageStatus::Published ? null : [
                    'label' => $message->status->label(),
                    'color' => $message->status->color(),
                ],
            ])
            ->all();
    }

    /**
     * @return list<array{href: string, name: string, meta: list<array{label: string, value: string}>, attachments_count: int, badge: array{label: string, color: string}|null}>
     */
    public function selectedSystemFolderItems(): array
    {
        $workspace = $this->workspace();
        $folder = $this->selectedSystemFolder();

        if (! $workspace || ! $folder) {
            return [];
        }

        return Message::query()
            ->with(['topic', 'sender.user', 'sender.agent', 'recipient.user', 'recipient.agent'])
            ->withCount('attachments')
            ->whereHas('topic', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->when($folder['slug'] === 'drafts', fn ($query) => $query->where('status', MessageStatus::Draft))
            ->when($folder['slug'] === 'updates', fn ($query) => $query->where('status', MessageStatus::Published))
            ->when($folder['slug'] === 'archived', fn ($query) => $query->where('status', MessageStatus::Archived))
            ->when($folder['slug'] !== 'archived' && ! $this->showArchived, fn ($query) => $query->where('status', '!=', MessageStatus::Archived))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Message $message) => [
                'href' => route('dashboard', [
                    'folder' => $folder['slug'],
                    'topic' => $message->topic->slug,
                    'message' => $message->slug,
                    'panel' => 'messages',
                ]),
                'name' => $message->title,
                'meta' => $message->listMeta(
                    showSender: true,
                    showRecipient: true,
                    recipientFallback: $message->topic->name,
                    timezone: Auth::user()->displayTimezone(),
                    recipientLabel: __('Topic'),
                ),
                'attachments_count' => $message->attachments_count,
                'sort' => $message->listSortValues(
                    recipientFallback: $message->topic->name,
                    dateKey: $folder['slug'] === 'drafts' ? 'saved' : 'sent',
                ),
                'badge' => $message->status === MessageStatus::Published ? null : [
                    'label' => $message->status->label(),
                    'color' => $message->status->color(),
                ],
            ])
            ->all();
    }

    /**
     * @return list<array{key: string, label: string, class: string}>
     */
    public function selectedMessageListColumns(): array
    {
        $folder = $this->selectedSystemFolder();
        $dateLabel = $folder && $folder['slug'] === 'drafts' ? __('Saved') : __('Posted');

        $columns = [
            ['key' => 'name', 'label' => __('Update'), 'class' => 'min-w-0 flex-1'],
        ];

        $columns[] = ['key' => 'from', 'label' => __('Author'), 'class' => 'w-28 shrink-0'];
        $columns[] = ['key' => 'to', 'label' => __('Topic'), 'class' => 'w-28 shrink-0'];

        $columns[] = ['key' => $folder && $folder['slug'] === 'drafts' ? 'saved' : 'sent', 'label' => $dateLabel, 'class' => 'w-28 shrink-0'];
        $columns[] = ['key' => 'attachments', 'label' => __('Files'), 'class' => 'w-24 shrink-0'];

        return $columns;
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

    /** @return list<string> */
    #[Computed]
    public function selectedAgentAvailableModels(): array
    {
        if (! $this->selectedAgentProvider) {
            return [];
        }

        $provider = Provider::tryFrom($this->selectedAgentProvider);

        return $provider ? $provider->models() : [];
    }

    #[Computed]
    public function selectedAgentShowReasoningEffort(): bool
    {
        if (! $this->selectedAgentProvider) {
            return false;
        }

        $provider = Provider::tryFrom($this->selectedAgentProvider);

        return $provider?->supportsReasoningEffort() ?? false;
    }

    public function updatedProvider(): void
    {
        $this->model = '';
        $this->reasoningEffort = '';
    }

    public function updatedSelectedAgentProvider(): void
    {
        $this->selectedAgentModel = '';
        $this->selectedAgentReasoningEffort = '';
    }

    public function updatedMobilePanel(): void
    {
        $this->normalizeMobilePanel();
    }

    public function updatedSelectedTopicSlug(): void
    {
        if ($this->selectedTopicSlug) {
            $this->selectedSystemFolderSlug = null;
        }

        $this->selectedMessageSlug = null;
        $this->messageTitle = '';
        $this->messageBody = '';
        $this->syncNewMessageTopic();
        $this->normalizeMobilePanel();
    }

    public function updatedSelectedSystemFolderSlug(): void
    {
        if ($this->selectedSystemFolderSlug) {
            $this->selectedTopicSlug = null;
            $this->selectedMessageSlug = null;
            $this->panelAction = null;
            $this->mobilePanel = 'messages';
        }

        $this->normalizeMobilePanel();
    }

    public function updatedSelectedMessageSlug(): void
    {
        if ($this->selectedMessageSlug) {
            $this->panelAction = null;
        }

        $this->syncSelectedMessageFields();
    }

    public function updatedSelectedAgentSlug(): void
    {
        if ($this->selectedAgentSlug) {
            $this->mobilePanel = 'agents';
        }

        $this->syncSelectedAgentFields();
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

        if (! $this->selectedTopic() && ! $this->selectedSystemFolder() && ! $this->isCreatingMessage() && $this->mobilePanel === 'messages') {
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
        $this->createDashboardMessageWithStatus(MessageStatus::Draft);
    }

    public function sendDashboardMessage(): void
    {
        $this->createDashboardMessageWithStatus(MessageStatus::Published);
    }

    private function createDashboardMessageWithStatus(MessageStatus $status): void
    {
        $workspace = $this->workspace();

        abort_unless($workspace, 403);

        $this->normalizeNewMessageTopic();

        $validated = $this->validate([
            'newMessageTitle' => ['required', 'string', 'max:255'],
            'newMessageBody' => ['nullable', 'string'],
            'newMessageTopicId' => ['required', 'integer'],
            'newMessageAgentIds' => ['array'],
            'newMessageAgentIds.*' => ['integer'],
            'newMessageUploads.*' => ['file', 'max:51200'],
        ], [], [
            'newMessageTitle' => __('title'),
            'newMessageBody' => __('body'),
            'newMessageTopicId' => __('topic'),
            'newMessageAgentIds' => __('requested agents'),
            'newMessageUploads.*' => __('attachment'),
        ]);

        $topic = $workspace->topics()->findOrFail($validated['newMessageTopicId']);
        $senderPrincipal = $workspace->principalForUser(Auth::user());

        $message = $topic->messages()->create([
            'title' => $validated['newMessageTitle'],
            'body' => $validated['newMessageBody'] ?: null,
            'status' => $status,
            'sender_principal_id' => $senderPrincipal->id,
            'recipient_principal_id' => null,
        ]);
        $message->assignAgents($validated['newMessageAgentIds']);

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
        $this->creatingMessageFromRoute = false;
        $this->mobilePanel = 'messages';
        $this->reset('newMessageTitle', 'newMessageBody', 'newMessageAgentIds', 'newMessageUploads');
        $this->newMessageTopicId = $topic->id;
        $this->syncSelectedMessageFields();

        Flux::toast(variant: 'success', text: $status === MessageStatus::Draft ? __('Draft created.') : __('Update posted.'));
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

    public function openAgent(string $agentSlug): void
    {
        $workspace = $this->workspace();

        abort_unless($workspace, 403);

        $agent = $workspace->agents()->where('slug', $agentSlug)->firstOrFail();

        $this->selectedAgentSlug = $agent->slug;
        $this->mobilePanel = 'agents';
        $this->syncSelectedAgentFields();
    }

    public function closeAgent(): void
    {
        $this->selectedAgentSlug = null;
        $this->syncSelectedAgentFields();
    }

    public function saveSelectedAgentDetails(): void
    {
        $agent = $this->selectedAgent();

        abort_unless($agent, 404);

        $validated = $this->validate([
            'selectedAgentName' => ['required', 'string', 'max:255'],
        ]);

        $agent->update(['name' => $validated['selectedAgentName']]);

        $this->selectedAgentSlug = $agent->fresh()->slug;

        Flux::toast(variant: 'success', text: __('Agent saved.'));
    }

    public function saveSelectedAgentVersion(): void
    {
        $agent = $this->selectedAgent();

        abort_unless($agent, 404);

        $validated = $this->validate([
            'selectedAgentProvider' => ['required', 'string', 'in:'.implode(',', array_column(Provider::cases(), 'value'))],
            'selectedAgentModel' => ['required', 'string', 'max:255'],
            'selectedAgentReasoningEffort' => ['nullable', 'string', 'in:'.implode(',', array_column(ReasoningEffort::cases(), 'value'))],
            'selectedAgentPrompt' => ['nullable', 'string'],
        ]);

        $agent->versions()->create([
            'provider' => $validated['selectedAgentProvider'],
            'model' => $validated['selectedAgentModel'],
            'reasoning_effort' => $validated['selectedAgentReasoningEffort'] ?: null,
            'prompt' => $validated['selectedAgentPrompt'] ?: null,
        ]);

        Flux::toast(variant: 'success', text: __('Version saved.'));
    }

    public function saveSelectedMessage(): void
    {
        $message = $this->selectedMessage();

        abort_unless($message && $message->status === MessageStatus::Draft, 403);

        $workspace = $this->workspace();

        abort_unless($workspace, 403);

        $validated = $this->validate([
            'messageTitle' => ['required', 'string', 'max:255'],
            'messageBody' => ['nullable', 'string'],
            'messageAgentIds' => ['array'],
            'messageAgentIds.*' => ['integer'],
        ], [], [
            'messageTitle' => __('title'),
            'messageBody' => __('body'),
            'messageAgentIds' => __('requested agents'),
        ]);

        $message->update([
            'title' => $validated['messageTitle'],
            'body' => $validated['messageBody'],
            'recipient_principal_id' => null,
        ]);
        $message->assignAgents($validated['messageAgentIds']);

        $this->selectedMessageSlug = $message->fresh()->slug;

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function publishSelectedMessage(): void
    {
        $message = $this->selectedMessage();

        abort_unless($message && $message->status === MessageStatus::Draft, 403);

        $this->saveSelectedMessage();

        $workspace = $this->workspace();

        abort_unless($workspace, 403);

        $message->fresh()->update([
            'sender_principal_id' => $message->sender_principal_id ?: $workspace->principalForUser(Auth::user())->id,
            'status' => MessageStatus::Published,
        ]);
    }

    public function uploadSelectedMessageAttachments(): void
    {
        $message = $this->selectedMessage();

        abort_unless($message && $message->status === MessageStatus::Draft, 403);

        $this->validate([
            'messageUploads.*' => ['file', 'max:51200'],
        ]);

        foreach ($this->messageUploads as $upload) {
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

        $this->reset('messageUploads');

        Flux::toast(variant: 'success', text: __('Attachments uploaded.'));
    }

    public function deleteSelectedMessageAttachment(int $attachmentId): void
    {
        $message = $this->selectedMessage();

        abort_unless($message && $message->status === MessageStatus::Draft, 403);

        $attachment = $message->attachments()->findOrFail($attachmentId);

        Storage::disk('public')->delete($attachment->path);

        $attachment->delete();

        Flux::toast(variant: 'success', text: __('Attachment deleted.'));
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
        $this->messageAgentIds = $message?->agentTasks()
            ->where('event_type', \App\Models\AgentTask::EventMessageAssigned)
            ->pluck('agent_id')
            ->map(fn ($id): int => (int) $id)
            ->all() ?? [];
    }

    private function syncNewMessageTopic(): void
    {
        $topic = $this->selectedTopic();

        if ($topic) {
            $this->newMessageTopicId = $topic->id;

            return;
        }

        if ($this->isCreatingMessage() && ! $this->newMessageTopicId) {
            $this->newMessageTopicId = $this->workspace()?->topics()->value('id');
        }
    }

    private function normalizeNewMessageTopic(): void
    {
        if ($this->newMessageTopicId) {
            return;
        }

        $this->syncNewMessageTopic();
    }

    private function isCreateRoute(): bool
    {
        return request()->routeIs('messages.create');
    }

    private function syncSelectedAgentFields(): void
    {
        $agent = $this->selectedAgent();
        $latest = $agent?->latestVersion;

        $this->selectedAgentName = $agent?->name ?? '';
        $this->selectedAgentProvider = $latest?->provider->value ?? '';
        $this->selectedAgentModel = $latest?->model ?? '';
        $this->selectedAgentReasoningEffort = $latest?->reasoning_effort?->value ?? '';
        $this->selectedAgentPrompt = $latest?->prompt ?? '';
    }
}; ?>

<div class="flex min-h-0 w-full flex-1">
    @if ($this->workspace())
        @php
            $hasSelectedMessagesPanel = (bool) ($this->selectedTopic() || $this->selectedSystemFolder() || $this->isCreatingMessage());
        @endphp

        <div @class([
            'grid min-h-0 flex-1 grid-cols-1 grid-rows-[minmax(0,1fr)] items-stretch gap-3 xl:auto-rows-fr',
            'xl:grid-cols-[16rem_minmax(0,1fr)_32rem]' => $this->selectedAgent(),
            'xl:grid-cols-[16rem_minmax(0,1fr)_19rem]' => ! $this->selectedAgent(),
        ])>
            <section
                id="topics-panel"
                data-mobile-panel="topics"
                @class([
                    'scroll-mt-4 flex h-full min-h-0 flex-col overflow-hidden rounded-xl border border-neutral-300 bg-white shadow-sm shadow-black/[0.04] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none',
                    'hidden xl:flex' => $this->mobilePanel !== 'topics',
                ])
            >
                <div class="flex items-center justify-between gap-3 border-b border-neutral-300 bg-blue-50 px-4 py-3 dark:border-white/10 dark:bg-blue-500/10">
                    <flux:heading size="sm">{{ __('Topics') }}</flux:heading>
                    <flux:modal.trigger name="new-topic">
                        <flux:button icon="plus" size="xs">{{ __('New topic') }}</flux:button>
                    </flux:modal.trigger>
                </div>

                @if ($this->topics()->isEmpty() && empty($this->systemFolders()))
                    <div class="bg-white px-4 py-6 xl:flex xl:flex-1 xl:items-start dark:bg-zinc-900/20">
                        <flux:text class="text-sm text-neutral-400 dark:text-neutral-600">{{ __('No topics') }}</flux:text>
                    </div>
                @else
                    <div class="divide-y divide-neutral-200 bg-white xl:flex-1 xl:overflow-auto dark:divide-white/5 dark:bg-zinc-900/20">
                        @foreach ($this->systemFolders() as $folder)
                            <a href="{{ route('dashboard', ['folder' => $folder['slug'], 'panel' => 'messages']) }}" wire:navigate
                               @class([
                                   'flex items-center gap-3 px-4 py-3 hover:bg-neutral-100 dark:hover:bg-white/5',
                                   'bg-blue-100/80 dark:bg-blue-500/15' => $selectedSystemFolderSlug === $folder['slug'],
                               ])>
                                <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-blue-50 text-blue-500 dark:bg-blue-500/10 dark:text-blue-300">
                                    <flux:icon :name="$folder['icon']" class="size-4" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ $folder['name'] }}</div>
                                </div>
                                @if ($folder['count'] > 0)
                                    <flux:badge color="zinc" size="sm" data-test="system-folder-{{ $folder['slug'] }}-count">{{ $folder['count'] }}</flux:badge>
                                @endif
                            </a>
                        @endforeach

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
                                    @if ($topic->published_count > 0)
                                        <flux:badge color="green" size="sm" title="{{ __('Updates') }}" data-test="topic-{{ $topic->slug }}-published-count" data-count="{{ $topic->published_count }}">{{ $topic->published_count }}</flux:badge>
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

            @if ($this->selectedTopic() || $this->selectedSystemFolder() || $this->isCreatingMessage())
                @php
                    $selectedDashboardMessage = $this->selectedMessage();
                    $selectedDashboardFolder = $this->selectedSystemFolder();
                @endphp

                <section
                    id="messages-panel"
                    data-mobile-panel="messages"
                    @class([
                        'scroll-mt-4 flex h-full min-h-0 flex-col overflow-hidden rounded-xl border border-neutral-300 bg-white shadow-sm shadow-black/[0.04] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none',
                        'hidden xl:flex' => $this->mobilePanel !== 'messages',
                    ])
                >
                    @if ($this->isCreatingMessage())
                        <div class="flex items-center justify-between gap-3 border-b border-neutral-300 bg-emerald-50 px-4 py-3 dark:border-white/10 dark:bg-emerald-500/10">
                            <flux:heading size="sm" class="min-w-0 flex-1 truncate">{{ __('New update') }}</flux:heading>

                            <flux:button :href="$this->selectedTopic() ? route('dashboard', ['topic' => $this->selectedTopic()->slug, 'panel' => 'messages']) : route('dashboard')" wire:navigate size="xs" variant="filled" icon="arrow-left">
                                {{ __('Updates') }}
                            </flux:button>
                        </div>

                        <div class="flex flex-1 flex-col gap-6 overflow-auto px-4 py-4 xl:min-h-0" data-test="dashboard-message-create-panel">
                            <form id="dashboard-new-message-form" wire:submit="createDashboardMessage" class="flex flex-col gap-6">
                                <flux:input wire:model="newMessageTitle" :label="__('Title')" required autofocus data-test="new-message-title" />

                                @include('partials.message-routing-fields', [
                                    'topicModel' => 'newMessageTopicId',
                                    'agentIdsModel' => 'newMessageAgentIds',
                                    'availableTopics' => $this->availableTopics,
                                    'availableAgents' => $this->newMessageAssignableAgents(),
                                    'canChangeTopic' => true,
                                    'testPrefix' => 'new-message',
                                ])

                                <flux:textarea wire:model="newMessageBody" :label="__('Body')" :placeholder="__('Write something...')" rows="12" data-test="new-message-body" />
                            </form>

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
                                <flux:button :href="$this->selectedTopic() ? route('dashboard', ['topic' => $this->selectedTopic()->slug, 'panel' => 'messages']) : route('dashboard')" wire:navigate variant="filled">
                                    {{ __('Cancel') }}
                                </flux:button>
                                <flux:button type="submit" form="dashboard-new-message-form" variant="filled" data-test="new-message-save-draft" wire:loading.attr="disabled" wire:target="newMessageUploads">{{ __('Save draft') }}</flux:button>
                                <flux:button wire:click="sendDashboardMessage" type="button" variant="primary" data-test="new-message-send" wire:loading.attr="disabled" wire:target="newMessageUploads">{{ __('Post') }}</flux:button>
                            </div>
                        </div>
                    @elseif ($selectedDashboardMessage)
                        <div class="flex items-center justify-between gap-3 border-b border-neutral-300 bg-emerald-50 px-4 py-3 dark:border-white/10 dark:bg-emerald-500/10">
                            <flux:heading size="sm" class="min-w-0 flex-1 truncate">{{ $selectedDashboardMessage->title }}</flux:heading>

                            <flux:button :href="route('dashboard', ['topic' => $this->selectedTopic()->slug, 'panel' => 'messages'])" wire:navigate size="xs" variant="filled" icon="arrow-left">
                                {{ __('Updates') }}
                            </flux:button>
                        </div>

                        <div class="flex flex-1 flex-col gap-6 overflow-auto px-4 py-4 xl:min-h-0" data-test="dashboard-message-panel">
                            @if ($selectedDashboardMessage->status === MessageStatus::Draft)
                                <form id="dashboard-selected-message-form" wire:submit="saveSelectedMessage" class="flex flex-col gap-4">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <flux:input wire:model="messageTitle" class="flex-1" required />

                                        <div class="flex shrink-0 items-center gap-2">
                                            <flux:badge :color="$selectedDashboardMessage->status->color()" size="sm">{{ $selectedDashboardMessage->status->label() }}</flux:badge>
                                            <flux:button wire:click="archiveSelectedMessage" type="button" size="sm" icon="archive-box">{{ __('Archive') }}</flux:button>
                                            <flux:button wire:click="publishSelectedMessage" type="button" size="sm" variant="primary" icon="paper-airplane">{{ __('Post') }}</flux:button>
                                        </div>
                                    </div>

                                    @include('partials.message-routing-fields', [
                                        'topicName' => $selectedDashboardMessage->topic->name,
                                        'agentIdsModel' => 'messageAgentIds',
                                        'availableAgents' => $this->selectedMessageAssignableAgents(),
                                        'canChangeTopic' => false,
                                        'testPrefix' => 'message',
                                    ])

                                    <flux:textarea wire:model="messageBody" :placeholder="__('Write something...')" rows="12" />
                                </form>

                                @include('partials.message-attachments', [
                                    'message' => $selectedDashboardMessage,
                                    'uploadAction' => 'uploadSelectedMessageAttachments',
                                    'uploadModel' => 'messageUploads',
                                    'uploadError' => 'messageUploads.*',
                                    'deleteAction' => 'deleteSelectedMessageAttachment',
                                ])

                                <div class="flex justify-end">
                                    <flux:button type="submit" form="dashboard-selected-message-form" size="sm" variant="filled">{{ __('Save draft') }}</flux:button>
                                </div>
                            @else
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <flux:heading size="xl" class="min-w-0 flex-1 truncate">{{ $selectedDashboardMessage->title }}</flux:heading>

                                    <div class="flex shrink-0 items-center gap-2">
                                        @if ($selectedDashboardMessage->status === MessageStatus::Published)
                                            <flux:button wire:click="unpublishSelectedMessage" size="sm" icon="arrow-uturn-left">{{ __('Return to draft') }}</flux:button>
                                            <flux:button wire:click="archiveSelectedMessage" size="sm" icon="archive-box">{{ __('Archive') }}</flux:button>
                                        @elseif ($selectedDashboardMessage->status === MessageStatus::Archived)
                                            <flux:badge :color="$selectedDashboardMessage->status->color()" size="sm">{{ $selectedDashboardMessage->status->label() }}</flux:badge>
                                            <flux:button wire:click="unarchiveSelectedMessage" size="sm" icon="archive-box-x-mark">{{ __('Unarchive') }}</flux:button>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    @if ($selectedDashboardMessage->sender)
                                        <flux:badge color="zinc" size="sm">{{ __('From') }}: {{ $selectedDashboardMessage->sender->label() }}</flux:badge>
                                    @endif

                                    <flux:badge color="zinc" size="sm">
                                        {{ __('Topic') }}:
                                        {{ $selectedDashboardMessage->topic->name }}
                                    </flux:badge>

                                    @foreach ($selectedDashboardMessage->assignedAgents as $agent)
                                        <flux:badge color="amber" size="sm">{{ __('Agent work') }}: {{ $agent->name }}</flux:badge>
                                    @endforeach
                                </div>

                                <div>
                                    @if ($selectedDashboardMessage->body)
                                        <flux:text class="whitespace-pre-wrap text-sm leading-relaxed text-neutral-700 dark:text-neutral-300">{{ $selectedDashboardMessage->body }}</flux:text>
                                    @else
                                        <flux:text class="text-sm text-neutral-400 dark:text-neutral-600">{{ __('No content.') }}</flux:text>
                                    @endif
                                </div>

                                @include('partials.message-attachments', [
                                    'message' => $selectedDashboardMessage,
                                    'uploadAction' => 'uploadSelectedMessageAttachments',
                                    'uploadModel' => 'messageUploads',
                                    'uploadError' => 'messageUploads.*',
                                    'deleteAction' => 'deleteSelectedMessageAttachment',
                                ])
                            @endif
                        </div>
                    @else
                        @include('partials.folder-view', [
                            'breadcrumbs' => [
                                ['label' => $this->workspace()->name, 'href' => route('dashboard')],
                                ['label' => $selectedDashboardFolder['name'] ?? $this->selectedTopic()?->name ?? __('New update')],
                            ],
                            'titleLabel' => __('Updates'),
                            'items' => collect($selectedDashboardFolder ? $this->selectedSystemFolderItems() : $this->selectedTopicItems()),
                            'icon' => 'document-text',
                            'iconClass' => 'size-12 text-neutral-400 group-hover:text-neutral-300',
                            'emptyText' => __('No updates'),
                            'createHref' => $this->selectedTopic() ? route('messages.create', ['topic' => $this->selectedTopic()->slug]) : route('messages.create'),
                            'createLabel' => __('New update'),
                            'createTest' => 'dashboard-new-message-button',
                            'showArchivedModel' => 'showArchived',
                            'listColumns' => $this->selectedMessageListColumns(),
                            'listDefaultSort' => $selectedDashboardFolder && $selectedDashboardFolder['slug'] === 'drafts' ? 'saved' : 'sent',
                            'listDefaultSortDirection' => 'desc',
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
                        'scroll-mt-4 flex h-full min-h-0 flex-col overflow-hidden rounded-xl border border-neutral-300 bg-white shadow-sm shadow-black/[0.04] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none',
                        'hidden xl:flex' => $this->mobilePanel !== 'messages',
                    ])
                >
                    <div class="flex items-center justify-between gap-3 border-b border-neutral-300 bg-emerald-50 px-4 py-3 dark:border-white/10 dark:bg-emerald-500/10">
                        <flux:heading size="sm">{{ __('Updates') }}</flux:heading>
                        <flux:button :href="route('messages.create')" wire:navigate size="xs" icon="plus" data-test="dashboard-new-message-button">
                            {{ __('New update') }}
                        </flux:button>
                    </div>

                    <div class="flex flex-1 items-center justify-center px-6 py-10 text-center">
                        <div class="space-y-2">
                            <flux:heading size="sm">{{ __('Select a topic') }}</flux:heading>
                            <flux:text class="text-sm text-neutral-400 dark:text-neutral-600">
                                {{ __('Choose a topic to view its updates.') }}
                            </flux:text>
                        </div>
                    </div>
                </section>
            @endif

            <div
                data-mobile-panel="agents"
                @class([
                    'h-full min-h-0 xl:block',
                    'hidden xl:block' => $this->mobilePanel !== 'agents',
                ])
            >
                @if ($selectedDashboardAgent = $this->selectedAgent())
                    <aside id="agents-panel" class="h-full min-h-0">
                        <div class="flex h-full min-h-0 flex-col overflow-hidden rounded-xl border border-neutral-300 bg-white shadow-sm shadow-black/[0.04] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none" data-test="dashboard-agent-panel">
                            <div class="flex items-center justify-between gap-3 border-b border-neutral-300 bg-amber-50 px-4 py-3 dark:border-white/10 dark:bg-amber-500/10">
                                <flux:heading size="sm" class="min-w-0 flex-1 truncate">{{ $selectedDashboardAgent->name }}</flux:heading>

                                <flux:button wire:click="closeAgent" size="xs" variant="filled" icon="arrow-left">
                                    {{ __('Agents') }}
                                </flux:button>
                            </div>

                            <div class="flex flex-1 flex-col gap-4 overflow-auto px-4 py-4 xl:min-h-0">
                                @if ($selectedDashboardAgent->latestVersion)
                                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm dark:border-amber-300/20 dark:bg-amber-500/10">
                                        <div class="font-medium text-amber-950 dark:text-amber-100">{{ __('Current') }}: v{{ $selectedDashboardAgent->latestVersion->version }}</div>
                                        <div class="text-amber-700 dark:text-amber-200">
                                            {{ $selectedDashboardAgent->latestVersion->provider->label() }} / {{ $selectedDashboardAgent->latestVersion->model }}
                                        </div>
                                    </div>
                                @endif

                                <div class="rounded-lg border border-neutral-200 dark:border-white/10">
                                    <div class="border-b border-neutral-100 px-4 py-3 dark:border-white/5">
                                        <flux:heading size="sm">{{ __('Agent details') }}</flux:heading>
                                    </div>

                                    <form wire:submit="saveSelectedAgentDetails" class="space-y-4 p-4">
                                        <flux:input wire:model="selectedAgentName" :label="__('Name')" type="text" required />

                                        <div class="flex justify-end">
                                            <flux:button type="submit" variant="filled">{{ __('Save agent') }}</flux:button>
                                        </div>
                                    </form>
                                </div>

                                <div class="rounded-lg border border-neutral-200 dark:border-white/10">
                                    <div class="border-b border-neutral-100 px-4 py-3 dark:border-white/5">
                                        <flux:heading size="sm">{{ __('New version') }}</flux:heading>
                                    </div>

                                    <form wire:submit="saveSelectedAgentVersion" class="space-y-4 p-4">
                                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                            <flux:select wire:model.live="selectedAgentProvider" :label="__('Provider')" placeholder="{{ __('Select provider…') }}" required>
                                                @foreach (Provider::cases() as $providerOption)
                                                    <flux:select.option :value="$providerOption->value">{{ $providerOption->label() }}</flux:select.option>
                                                @endforeach
                                            </flux:select>

                                            <flux:select wire:model="selectedAgentModel" :label="__('Model')" placeholder="{{ __('Select model…') }}" :disabled="!$selectedAgentProvider" required>
                                                @foreach ($this->selectedAgentAvailableModels as $availableModel)
                                                    <flux:select.option :value="$availableModel">{{ $availableModel }}</flux:select.option>
                                                @endforeach
                                            </flux:select>
                                        </div>

                                        @if ($this->selectedAgentShowReasoningEffort)
                                            <flux:select wire:model="selectedAgentReasoningEffort" :label="__('Reasoning effort')" placeholder="{{ __('Select effort…') }}">
                                                <flux:select.option value="">{{ __('None') }}</flux:select.option>
                                                @foreach (ReasoningEffort::cases() as $effort)
                                                    <flux:select.option :value="$effort->value">{{ $effort->label() }}</flux:select.option>
                                                @endforeach
                                            </flux:select>
                                        @endif

                                        <flux:textarea wire:model="selectedAgentPrompt" :label="__('Prompt')" rows="7" :placeholder="__('System prompt…')" />

                                        <div class="flex justify-end">
                                            <flux:button type="submit" variant="primary">{{ __('Save version') }}</flux:button>
                                        </div>
                                    </form>
                                </div>

                                <div class="rounded-lg border border-neutral-200 dark:border-white/10">
                                    <div class="border-b border-neutral-100 px-4 py-3 dark:border-white/5">
                                        <flux:heading size="sm">{{ __('Version history') }}</flux:heading>
                                    </div>

                                    @if ($selectedDashboardAgent->versions->isEmpty())
                                        <div class="px-4 py-6 text-center">
                                            <flux:text class="text-sm text-neutral-400 dark:text-neutral-600">{{ __('No versions yet.') }}</flux:text>
                                        </div>
                                    @else
                                        <div class="divide-y divide-neutral-100 dark:divide-white/5">
                                            @foreach ($selectedDashboardAgent->versions as $version)
                                                <div class="px-4 py-3">
                                                    <div class="flex items-center justify-between gap-3">
                                                        <flux:badge color="zinc" size="sm">v{{ $version->version }}</flux:badge>
                                                        <flux:text class="text-xs text-neutral-400" :title="$version->created_at->timezone(Auth::user()->displayTimezone())->isoFormat('LLLL')">{{ $version->created_at->diffForHumans() }}</flux:text>
                                                    </div>
                                                    <div class="mt-1.5 space-y-0.5">
                                                        <flux:text class="text-xs text-neutral-600 dark:text-neutral-400">
                                                            {{ $version->provider->label() }} / {{ $version->model }}
                                                        </flux:text>
                                                        @if ($version->reasoning_effort)
                                                            <flux:text class="text-xs text-neutral-500">
                                                                {{ __('Reasoning:') }} {{ $version->reasoning_effort->label() }}
                                                            </flux:text>
                                                        @endif
                                                        @if ($version->prompt)
                                                            <flux:text class="line-clamp-2 text-xs text-neutral-400">{{ $version->prompt }}</flux:text>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </aside>
                @else
                    @include('partials.workspace-agents-rail', [
                        'agents' => $this->agents(),
                        'createModal' => 'new-dashboard-agent',
                        'panelId' => 'agents-panel',
                        'asideClass' => 'h-full min-h-0',
                        'containerClass' => 'h-full min-h-0 xl:min-h-0',
                        'sticky' => false,
                        'assignedAgentIds' => $this->selectedTopic() ? $this->assignedAgentIds() : [],
                        'assignAction' => $this->selectedTopic() ? 'assignAgent' : null,
                        'unassignAction' => $this->selectedTopic() ? 'unassignAgent' : null,
                        'selectAction' => 'openAgent',
                    ])
                @endif
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
                    @if ($hasSelectedMessagesPanel) wire:click="showMobilePanel('messages')" @endif
                    data-mobile-nav="messages"
                    aria-pressed="{{ $this->mobilePanel === 'messages' ? 'true' : 'false' }}"
                    @disabled(! $hasSelectedMessagesPanel)
                    @class([
                        'flex items-center justify-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition',
                        'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-400/30 dark:bg-emerald-500/15 dark:text-emerald-200' => $hasSelectedMessagesPanel && $this->mobilePanel === 'messages',
                        'border-neutral-200 bg-neutral-50 text-neutral-700 dark:border-white/10 dark:bg-white/5 dark:text-neutral-200' => $hasSelectedMessagesPanel && $this->mobilePanel !== 'messages',
                        'cursor-not-allowed border-neutral-200 bg-neutral-50 text-neutral-300 opacity-60 dark:border-white/10 dark:bg-white/5 dark:text-neutral-600' => ! $hasSelectedMessagesPanel,
                    ])
                >
                    <flux:icon name="document-text" class="size-4" />
                    <span>{{ __('Updates') }}</span>
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
