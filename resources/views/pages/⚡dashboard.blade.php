<?php

use App\Enums\PostStatus;
use App\Enums\Provider;
use App\Enums\ReasoningEffort;
use App\Models\Agent;
use App\Models\Post;
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

    #[Url(as: 'post')]
    public ?string $selectedPostSlug = null;

    #[Url(as: 'action')]
    public ?string $panelAction = null;

    #[Url(as: 'agent')]
    public ?string $selectedAgentSlug = null;

    #[Url(as: 'panel')]
    public string $mobilePanel = 'topics';

    public bool $creatingPostFromRoute = false;

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

    public string $postTitle = '';

    public string $postBody = '';

    /** @var list<int> */
    public array $postAgentIds = [];

    public string $newPostTitle = '';

    public string $newPostBody = '';

    public ?int $newPostTopicId = null;

    /** @var list<int> */
    public array $newPostAgentIds = [];

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $newPostUploads = [];

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $postUploads = [];

    public function mount(): void
    {
        if ($this->isCreateRoute()) {
            $this->creatingPostFromRoute = true;
            $this->mobilePanel = 'posts';
        }

        $this->normalizeMobilePanel();
        $this->syncSelectedPostFields();
        $this->syncNewPostTopic();
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

    public function selectedPost(): ?Post
    {
        $topic = $this->selectedTopic();

        if (! $topic || ! $this->selectedPostSlug) {
            return null;
        }

        return $topic->posts()->where('slug', $this->selectedPostSlug)->first();
    }

    public function postsPanelReturnRoute(): string
    {
        if ($folder = $this->selectedSystemFolder()) {
            return route('dashboard', ['folder' => $folder['slug'], 'panel' => 'posts']);
        }

        if ($topic = $this->selectedTopic()) {
            return route('dashboard', ['topic' => $topic->slug, 'panel' => 'posts']);
        }

        return route('dashboard');
    }

    public function postsPanelReturnLabel(): string
    {
        if ($folder = $this->selectedSystemFolder()) {
            return $folder['name'];
        }

        if ($topic = $this->selectedTopic()) {
            return $topic->name;
        }

        return __('Dashboard');
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

    public function isCreatingPost(): bool
    {
        return $this->creatingPostFromRoute || $this->panelAction === 'new-post';
    }

    /** @return list<array{slug: string, name: string, icon: string, count: int}> */
    public function systemFolders(): array
    {
        $workspace = $this->workspace();

        if (! $workspace) {
            return [];
        }

        $counts = Post::query()
            ->whereHas('topic', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            [
                'slug' => 'inbox',
                'name' => __('Inbox'),
                'icon' => 'inbox',
                'count' => (int) ($counts[PostStatus::Published->value] ?? 0),
            ],
            [
                'slug' => 'drafts',
                'name' => __('Drafts'),
                'icon' => 'document',
                'count' => (int) ($counts[PostStatus::Draft->value] ?? 0),
            ],
            [
                'slug' => 'archived',
                'name' => __('Archived'),
                'icon' => 'archive-box',
                'count' => (int) ($counts[PostStatus::Archived->value] ?? 0),
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
    public function newPostAssignableAgents(): \Illuminate\Database\Eloquent\Collection
    {
        $workspace = $this->workspace();

        if (! $workspace || ! $this->newPostTopicId) {
            return Agent::query()->whereNull('id')->get();
        }

        $topic = $workspace->topics()->find($this->newPostTopicId);

        if (! $topic) {
            return Agent::query()->whereNull('id')->get();
        }

        return $topic->agents()->with('latestVersion')->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Agent>
     */
    public function selectedPostAssignableAgents(): \Illuminate\Database\Eloquent\Collection
    {
        $post = $this->selectedPost();

        if (! $post) {
            return Agent::query()->whereNull('id')->get();
        }

        return $post->topic->agents()->with('latestVersion')->get();
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
                'posts as draft_count' => fn ($q) => $q->where('status', PostStatus::Draft),
                'posts as published_count' => fn ($q) => $q->where('status', PostStatus::Published),
                'posts as archived_count' => fn ($q) => $q->where('status', PostStatus::Archived),
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

        return $topic->posts()
            ->with(['sender.user', 'sender.agent'])
            ->withCount('attachments')
            ->when(! $this->showArchived, fn ($query) => $query->where('status', '!=', PostStatus::Archived))
            ->where('status', '!=', PostStatus::Draft)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Post $post) => [
                'href' => route('dashboard', ['topic' => $topic->slug, 'post' => $post->slug, 'panel' => 'posts']),
                'name' => $post->title,
                'meta' => $post->listMeta(showSender: true, showRecipient: false, timezone: Auth::user()->displayTimezone()),
                'attachments_count' => $post->attachments_count,
                'sort' => $post->listSortValues(dateKey: 'sent'),
                'badge' => $post->status === PostStatus::Published ? null : [
                    'label' => $post->status->label(),
                    'color' => $post->status->color(),
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

        return Post::query()
            ->with(['topic', 'sender.user', 'sender.agent', 'recipient.user', 'recipient.agent'])
            ->withCount('attachments')
            ->whereHas('topic', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->when($folder['slug'] === 'drafts', fn ($query) => $query->where('status', PostStatus::Draft))
            ->when($folder['slug'] === 'inbox', fn ($query) => $query->where('status', PostStatus::Published))
            ->when($folder['slug'] === 'archived', fn ($query) => $query->where('status', PostStatus::Archived))
            ->when($folder['slug'] !== 'archived' && ! $this->showArchived, fn ($query) => $query->where('status', '!=', PostStatus::Archived))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Post $post) => [
                'href' => route('dashboard', [
                    'folder' => $folder['slug'],
                    'topic' => $post->topic->slug,
                    'post' => $post->slug,
                    'panel' => 'posts',
                ]),
                'name' => $post->title,
                'meta' => $post->listMeta(
                    showSender: true,
                    showRecipient: true,
                    recipientFallback: $post->topic->name,
                    timezone: Auth::user()->displayTimezone(),
                    recipientLabel: __('Topic'),
                ),
                'attachments_count' => $post->attachments_count,
                'sort' => $post->listSortValues(
                    recipientFallback: $post->topic->name,
                    dateKey: $folder['slug'] === 'drafts' ? 'saved' : 'sent',
                ),
                'badge' => null,
            ])
            ->all();
    }

    /**
     * @return list<array{key: string, label: string, class: string}>
     */
    public function selectedPostListColumns(): array
    {
        $folder = $this->selectedSystemFolder();
        $dateLabel = $folder && $folder['slug'] === 'drafts' ? __('Saved') : __('Posted');

        $columns = [
            ['key' => 'name', 'label' => __('Post'), 'class' => 'min-w-0 flex-1'],
        ];

        if (! ($folder && $folder['slug'] === 'drafts')) {
            $columns[] = ['key' => 'from', 'label' => __('Author'), 'class' => 'w-28 shrink-0'];
        }

        $columns[] = ['key' => 'to', 'label' => __('Topic'), 'class' => 'w-28 shrink-0'];

        $columns[] = ['key' => $folder && $folder['slug'] === 'drafts' ? 'saved' : 'sent', 'label' => $dateLabel, 'class' => 'w-28 shrink-0'];
        $columns[] = ['key' => 'attachments', 'label' => __('Files'), 'class' => 'w-12 shrink-0 justify-center'];

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

        $this->selectedPostSlug = null;
        $this->postTitle = '';
        $this->postBody = '';
        $this->syncNewPostTopic();
        $this->normalizeMobilePanel();
    }

    public function updatedSelectedSystemFolderSlug(): void
    {
        if ($this->selectedSystemFolderSlug) {
            $this->selectedTopicSlug = null;
            $this->selectedPostSlug = null;
            $this->panelAction = null;
            $this->mobilePanel = 'posts';
        }

        $this->normalizeMobilePanel();
    }

    public function updatedSelectedPostSlug(): void
    {
        if ($this->selectedPostSlug) {
            $this->panelAction = null;
        }

        $this->syncSelectedPostFields();
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
        if ($this->isCreatingPost()) {
            $this->selectedPostSlug = null;
            $this->syncNewPostTopic();
        }
    }

    public function showMobilePanel(string $panel): void
    {
        $this->mobilePanel = $panel;
        $this->normalizeMobilePanel();
    }

    private function normalizeMobilePanel(): void
    {
        if (! in_array($this->mobilePanel, ['topics', 'posts', 'agents'], true)) {
            $this->mobilePanel = 'topics';
        }

        if (! $this->selectedTopic() && ! $this->selectedSystemFolder() && ! $this->isCreatingPost() && $this->mobilePanel === 'posts') {
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

    public function createDashboardPost(): void
    {
        $this->createDashboardPostWithStatus(PostStatus::Draft);
    }

    public function sendDashboardPost(): void
    {
        $this->createDashboardPostWithStatus(PostStatus::Published);
    }

    private function createDashboardPostWithStatus(PostStatus $status): void
    {
        $workspace = $this->workspace();

        abort_unless($workspace, 403);

        $this->normalizeNewPostTopic();

        $validated = $this->validate([
            'newPostTitle' => ['required', 'string', 'max:255'],
            'newPostBody' => ['nullable', 'string'],
            'newPostTopicId' => ['required', 'integer'],
            'newPostAgentIds' => ['array'],
            'newPostAgentIds.*' => ['integer'],
            'newPostUploads.*' => ['file', 'max:51200'],
        ], [], [
            'newPostTitle' => __('title'),
            'newPostBody' => __('body'),
            'newPostTopicId' => __('topic'),
            'newPostAgentIds' => __('requested agents'),
            'newPostUploads.*' => __('attachment'),
        ]);

        $topic = $workspace->topics()->findOrFail($validated['newPostTopicId']);
        $senderPrincipal = $workspace->principalForUser(Auth::user());

        $post = $topic->posts()->create([
            'title' => $validated['newPostTitle'],
            'body' => $validated['newPostBody'] ?: null,
            'status' => $status,
            'sender_principal_id' => $senderPrincipal->id,
            'recipient_principal_id' => null,
        ]);
        $post->assignAgents($validated['newPostAgentIds']);

        foreach ($this->newPostUploads as $upload) {
            $filename = $upload->getClientOriginalName();
            $path = $upload->storeAs(
                'attachments/'.Str::uuid(),
                $filename,
                'public'
            );

            $post->attachments()->create([
                'filename' => $filename,
                'path' => $path,
                'mime_type' => $upload->getMimeType(),
                'size' => $upload->getSize(),
            ]);
        }

        $this->selectedTopicSlug = $topic->slug;
        $this->selectedPostSlug = $post->slug;
        $this->panelAction = null;
        $this->creatingPostFromRoute = false;
        $this->mobilePanel = 'posts';
        $this->reset('newPostTitle', 'newPostBody', 'newPostAgentIds', 'newPostUploads');
        $this->newPostTopicId = $topic->id;
        $this->syncSelectedPostFields();

        Flux::toast(variant: 'success', text: $status === PostStatus::Draft ? __('Draft created.') : __('Post published.'));
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

    public function saveSelectedPost(): void
    {
        $post = $this->selectedPost();

        abort_unless($post && $post->status === PostStatus::Draft, 403);

        $workspace = $this->workspace();

        abort_unless($workspace, 403);

        $validated = $this->validate([
            'postTitle' => ['required', 'string', 'max:255'],
            'postBody' => ['nullable', 'string'],
            'postAgentIds' => ['array'],
            'postAgentIds.*' => ['integer'],
        ], [], [
            'postTitle' => __('title'),
            'postBody' => __('body'),
            'postAgentIds' => __('requested agents'),
        ]);

        $post->update([
            'title' => $validated['postTitle'],
            'body' => $validated['postBody'],
            'recipient_principal_id' => null,
        ]);
        $post->assignAgents($validated['postAgentIds']);

        $this->selectedPostSlug = $post->fresh()->slug;

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function publishSelectedPost(): void
    {
        $post = $this->selectedPost();

        abort_unless($post && $post->status === PostStatus::Draft, 403);

        $this->saveSelectedPost();

        $workspace = $this->workspace();

        abort_unless($workspace, 403);

        $post->fresh()->update([
            'sender_principal_id' => $post->sender_principal_id ?: $workspace->principalForUser(Auth::user())->id,
            'status' => PostStatus::Published,
        ]);
    }

    public function uploadSelectedPostAttachments(): void
    {
        $post = $this->selectedPost();

        abort_unless($post && $post->status === PostStatus::Draft, 403);

        $this->validate([
            'postUploads.*' => ['file', 'max:51200'],
        ]);

        foreach ($this->postUploads as $upload) {
            $filename = $upload->getClientOriginalName();
            $path = $upload->storeAs(
                'attachments/'.Str::uuid(),
                $filename,
                'public'
            );

            $post->attachments()->create([
                'filename' => $filename,
                'path' => $path,
                'mime_type' => $upload->getMimeType(),
                'size' => $upload->getSize(),
            ]);
        }

        $this->reset('postUploads');

        Flux::toast(variant: 'success', text: __('Attachments uploaded.'));
    }

    public function deleteSelectedPostAttachment(int $attachmentId): void
    {
        $post = $this->selectedPost();

        abort_unless($post && $post->status === PostStatus::Draft, 403);

        $attachment = $post->attachments()->findOrFail($attachmentId);

        Storage::disk('public')->delete($attachment->path);

        $attachment->delete();

        Flux::toast(variant: 'success', text: __('Attachment deleted.'));
    }

    public function archiveSelectedPost(): void
    {
        $post = $this->selectedPost();

        abort_unless($post, 404);

        $post->update(['status' => PostStatus::Archived]);
    }

    public function unpublishSelectedPost(): void
    {
        $post = $this->selectedPost();

        abort_unless($post && $post->status === PostStatus::Published, 403);

        $post->update(['status' => PostStatus::Draft]);

        $this->syncSelectedPostFields();
    }

    public function unarchiveSelectedPost(): void
    {
        $post = $this->selectedPost();

        abort_unless($post && $post->status === PostStatus::Archived, 403);

        $post->update(['status' => PostStatus::Draft]);

        $this->syncSelectedPostFields();
    }

    private function syncSelectedPostFields(): void
    {
        $post = $this->selectedPost();

        $this->postTitle = $post?->title ?? '';
        $this->postBody = $post?->body ?? '';
        $this->postAgentIds = $post?->agentTasks()
            ->where('event_type', \App\Models\AgentTask::EventPostAssigned)
            ->pluck('agent_id')
            ->map(fn ($id): int => (int) $id)
            ->all() ?? [];
    }

    private function syncNewPostTopic(): void
    {
        $topic = $this->selectedTopic();

        if ($topic) {
            $this->newPostTopicId = $topic->id;

            return;
        }

        if ($this->isCreatingPost() && ! $this->newPostTopicId) {
            $this->newPostTopicId = $this->workspace()?->topics()->value('id');
        }
    }

    private function normalizeNewPostTopic(): void
    {
        if ($this->newPostTopicId) {
            return;
        }

        $this->syncNewPostTopic();
    }

    private function isCreateRoute(): bool
    {
        return request()->routeIs('posts.create');
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
            $hasSelectedPostsPanel = (bool) ($this->selectedTopic() || $this->selectedSystemFolder() || $this->isCreatingPost());
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
                            <a href="{{ route('dashboard', ['folder' => $folder['slug'], 'panel' => 'posts']) }}" wire:navigate
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
                            <a href="{{ route('dashboard', ['topic' => $topic->slug, 'panel' => 'posts']) }}" wire:navigate
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
                                        <flux:badge color="green" size="sm" title="{{ __('Inbox') }}" data-test="topic-{{ $topic->slug }}-published-count" data-count="{{ $topic->published_count }}">{{ $topic->published_count }}</flux:badge>
                                    @endif
                                    @if ($showArchived && $selectedTopicSlug === $topic->slug && $topic->archived_count > 0)
                                        <flux:badge color="yellow" size="sm" title="{{ __('Archived posts') }}" data-test="topic-{{ $topic->slug }}-archived-count" data-count="{{ $topic->archived_count }}">{{ $topic->archived_count }}</flux:badge>
                                    @endif
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>

            @if ($this->selectedTopic() || $this->selectedSystemFolder() || $this->isCreatingPost())
                @php
                    $selectedDashboardPost = $this->selectedPost();
                    $selectedDashboardFolder = $this->selectedSystemFolder();
                @endphp

                <section
                    id="posts-panel"
                    data-mobile-panel="posts"
                    @class([
                        'scroll-mt-4 flex h-full min-h-0 flex-col overflow-hidden rounded-xl border border-neutral-300 bg-white shadow-sm shadow-black/[0.04] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none',
                        'hidden xl:flex' => $this->mobilePanel !== 'posts',
                    ])
                >
                    @if ($this->isCreatingPost())
                        <div class="flex items-center justify-between gap-3 border-b border-neutral-300 bg-emerald-50 px-4 py-3 dark:border-white/10 dark:bg-emerald-500/10">
                            <flux:heading size="sm" class="min-w-0 flex-1 truncate">{{ __('New post') }}</flux:heading>

                            <flux:button :href="$this->postsPanelReturnRoute()" wire:navigate size="xs" variant="filled" icon="arrow-left" data-test="posts-panel-return">
                                {{ $this->postsPanelReturnLabel() }}
                            </flux:button>
                        </div>

                        <div class="flex flex-1 flex-col gap-6 overflow-auto px-4 py-4 xl:min-h-0" data-test="dashboard-post-create-panel">
                            <form id="dashboard-new-post-form" wire:submit="createDashboardPost" class="flex flex-col gap-6">
                                <flux:input wire:model="newPostTitle" :label="__('Title')" required autofocus data-test="new-post-title" />

                                @include('partials.post-routing-fields', [
                                    'topicModel' => 'newPostTopicId',
                                    'agentIdsModel' => 'newPostAgentIds',
                                    'availableTopics' => $this->availableTopics,
                                    'availableAgents' => $this->newPostAssignableAgents(),
                                    'canChangeTopic' => true,
                                    'testPrefix' => 'new-post',
                                ])

                                <flux:textarea wire:model="newPostBody" :label="__('Body')" :placeholder="__('Write something...')" rows="12" data-test="new-post-body" />
                            </form>

                            <div class="flex flex-col gap-3">
                                <flux:heading size="sm">{{ __('Attachments') }}</flux:heading>

                                <div x-data="{ uploading: false, progress: 0 }"
                                     x-on:livewire-upload-start="uploading = true"
                                     x-on:livewire-upload-finish="uploading = false"
                                     x-on:livewire-upload-error="uploading = false"
                                     x-on:livewire-upload-progress="progress = $event.detail.progress"
                                     class="flex flex-col gap-2">
                                    <flux:input type="file" wire:model="newPostUploads" multiple />

                                    <div x-show="uploading" class="h-1 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-white/10">
                                        <div class="h-full rounded-full bg-blue-500 transition-all" :style="`width: ${progress}%`"></div>
                                    </div>

                                    @error('newPostUploads.*')
                                        <flux:error>{{ $message }}</flux:error>
                                    @enderror
                                </div>
                            </div>

                            <div class="flex justify-end gap-2">
                                <flux:button :href="$this->postsPanelReturnRoute()" wire:navigate variant="filled">
                                    {{ __('Cancel') }}
                                </flux:button>
                                <flux:button type="submit" form="dashboard-new-post-form" variant="filled" data-test="new-post-save-draft" wire:loading.attr="disabled" wire:target="newPostUploads">{{ __('Save draft') }}</flux:button>
                                <flux:button wire:click="sendDashboardPost" type="button" variant="primary" data-test="new-post-send" wire:loading.attr="disabled" wire:target="newPostUploads">{{ __('Post') }}</flux:button>
                            </div>
                        </div>
                    @elseif ($selectedDashboardPost)
                        <div class="flex items-center justify-between gap-3 border-b border-neutral-300 bg-emerald-50 px-4 py-3 dark:border-white/10 dark:bg-emerald-500/10">
                            <flux:heading size="sm" class="min-w-0 flex-1 truncate">{{ $selectedDashboardPost->title }}</flux:heading>

                            <flux:button :href="$this->postsPanelReturnRoute()" wire:navigate size="xs" variant="filled" icon="arrow-left" data-test="posts-panel-return">
                                {{ $this->postsPanelReturnLabel() }}
                            </flux:button>
                        </div>

                        <div class="flex flex-1 flex-col gap-6 overflow-auto px-4 py-4 xl:min-h-0" data-test="dashboard-post-panel">
                            @if ($selectedDashboardPost->status === PostStatus::Draft)
                                <form id="dashboard-selected-post-form" wire:submit="saveSelectedPost" class="flex flex-col gap-4">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <flux:input wire:model="postTitle" class="flex-1" required />

                                        <div class="flex shrink-0 items-center gap-2">
                                            <flux:button wire:click="archiveSelectedPost" type="button" size="sm" icon="archive-box" icon:variant="outline">{{ __('Archive') }}</flux:button>
                                            <flux:button wire:click="publishSelectedPost" type="button" size="sm" variant="primary" icon="paper-airplane">{{ __('Post') }}</flux:button>
                                        </div>
                                    </div>

                                    @include('partials.post-routing-fields', [
                                        'topicName' => $selectedDashboardPost->topic->name,
                                        'agentIdsModel' => 'postAgentIds',
                                        'availableAgents' => $this->selectedPostAssignableAgents(),
                                        'canChangeTopic' => false,
                                        'testPrefix' => 'post',
                                    ])

                                    <flux:textarea wire:model="postBody" :placeholder="__('Write something...')" rows="12" />
                                </form>

                                @include('partials.post-attachments', [
                                    'post' => $selectedDashboardPost,
                                    'uploadAction' => 'uploadSelectedPostAttachments',
                                    'uploadModel' => 'postUploads',
                                    'uploadError' => 'postUploads.*',
                                    'deleteAction' => 'deleteSelectedPostAttachment',
                                ])

                                <div class="flex justify-end">
                                    <flux:button type="submit" form="dashboard-selected-post-form" size="sm" variant="filled">{{ __('Save draft') }}</flux:button>
                                </div>
                            @else
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <flux:heading size="xl" class="min-w-0 flex-1 truncate">{{ $selectedDashboardPost->title }}</flux:heading>

                                    <div class="flex shrink-0 items-center gap-2">
                                        @if ($selectedDashboardPost->status === PostStatus::Published)
                                            <flux:button wire:click="unpublishSelectedPost" size="sm" icon="pencil-square" icon:variant="outline">{{ __('Move to drafts') }}</flux:button>
                                            <flux:button wire:click="archiveSelectedPost" size="sm" icon="archive-box" icon:variant="outline">{{ __('Archive') }}</flux:button>
                                        @elseif ($selectedDashboardPost->status === PostStatus::Archived)
                                            <flux:badge :color="$selectedDashboardPost->status->color()" size="sm">{{ $selectedDashboardPost->status->label() }}</flux:badge>
                                            <flux:button wire:click="unarchiveSelectedPost" size="sm" icon="archive-box-x-mark">{{ __('Unarchive') }}</flux:button>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    @if ($selectedDashboardPost->sender)
                                        <flux:badge color="zinc" size="sm">{{ __('From') }}: {{ $selectedDashboardPost->sender->label() }}</flux:badge>
                                    @endif

                                    <flux:badge color="zinc" size="sm">
                                        {{ __('Topic') }}:
                                        {{ $selectedDashboardPost->topic->name }}
                                    </flux:badge>

                                    @foreach ($selectedDashboardPost->assignedAgents as $agent)
                                        <flux:badge color="amber" size="sm">{{ __('Agent work') }}: {{ $agent->name }}</flux:badge>
                                    @endforeach
                                </div>

                                <div>
                                    @if ($selectedDashboardPost->body)
                                        <flux:text class="whitespace-pre-wrap text-sm leading-relaxed text-neutral-700 dark:text-neutral-300">{{ $selectedDashboardPost->body }}</flux:text>
                                    @else
                                        <flux:text class="text-sm text-neutral-400 dark:text-neutral-600">{{ __('No content.') }}</flux:text>
                                    @endif
                                </div>

                                @include('partials.post-attachments', [
                                    'post' => $selectedDashboardPost,
                                    'uploadAction' => 'uploadSelectedPostAttachments',
                                    'uploadModel' => 'postUploads',
                                    'uploadError' => 'postUploads.*',
                                    'deleteAction' => 'deleteSelectedPostAttachment',
                                ])
                            @endif
                        </div>
                    @else
                        @include('partials.folder-view', [
                            'breadcrumbs' => [
                                ['label' => $this->workspace()->name, 'href' => route('dashboard')],
                                ['label' => $selectedDashboardFolder['name'] ?? $this->selectedTopic()?->name ?? __('New post')],
                            ],
                            'titleLabel' => __('Inbox'),
                            'items' => collect($selectedDashboardFolder ? $this->selectedSystemFolderItems() : $this->selectedTopicItems()),
                            'icon' => 'document-text',
                            'iconClass' => 'size-12 text-neutral-400 group-hover:text-neutral-300',
                            'emptyText' => __('No inbox'),
                            'createHref' => $this->selectedTopic() ? route('posts.create', ['topic' => $this->selectedTopic()->slug]) : route('posts.create'),
                            'createLabel' => __('New post'),
                            'createTest' => 'dashboard-new-post-button',
                            'showArchivedModel' => 'showArchived',
                            'listColumns' => $this->selectedPostListColumns(),
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
                    id="posts-panel"
                    data-mobile-panel="posts"
                    @class([
                        'scroll-mt-4 flex h-full min-h-0 flex-col overflow-hidden rounded-xl border border-neutral-300 bg-white shadow-sm shadow-black/[0.04] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none',
                        'hidden xl:flex' => $this->mobilePanel !== 'posts',
                    ])
                >
                    <div class="flex items-center justify-between gap-3 border-b border-neutral-300 bg-emerald-50 px-4 py-3 dark:border-white/10 dark:bg-emerald-500/10">
                        <flux:heading size="sm">{{ __('Inbox') }}</flux:heading>
                        <flux:button :href="route('posts.create')" wire:navigate size="xs" icon="plus" data-test="dashboard-new-post-button">
                            {{ __('New post') }}
                        </flux:button>
                    </div>

                    <div class="flex flex-1 items-center justify-center px-6 py-10 text-center">
                        <div class="space-y-2">
                            <flux:heading size="sm">{{ __('Select a topic') }}</flux:heading>
                            <flux:text class="text-sm text-neutral-400 dark:text-neutral-600">
                                {{ __('Choose a topic to view its inbox.') }}
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
                    @if ($hasSelectedPostsPanel) wire:click="showMobilePanel('posts')" @endif
                    data-mobile-nav="posts"
                    aria-pressed="{{ $this->mobilePanel === 'posts' ? 'true' : 'false' }}"
                    @disabled(! $hasSelectedPostsPanel)
                    @class([
                        'flex items-center justify-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition',
                        'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-400/30 dark:bg-emerald-500/15 dark:text-emerald-200' => $hasSelectedPostsPanel && $this->mobilePanel === 'posts',
                        'border-neutral-200 bg-neutral-50 text-neutral-700 dark:border-white/10 dark:bg-white/5 dark:text-neutral-200' => $hasSelectedPostsPanel && $this->mobilePanel !== 'posts',
                        'cursor-not-allowed border-neutral-200 bg-neutral-50 text-neutral-300 opacity-60 dark:border-white/10 dark:bg-white/5 dark:text-neutral-600' => ! $hasSelectedPostsPanel,
                    ])
                >
                    <flux:icon name="document-text" class="size-4" />
                    <span>{{ __('Inbox') }}</span>
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
