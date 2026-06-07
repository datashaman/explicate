<?php

use App\Actions\Agents\CreateAgent;
use App\Actions\Agents\CreateAgentVersion;
use App\Actions\Posts\CreatePost;
use App\Actions\Posts\DeletePostAttachment;
use App\Actions\Posts\UpdateDraftPost;
use App\Enums\PostFolder;
use App\Enums\PostListColumn;
use App\Enums\PostStatus;
use App\Enums\Provider;
use App\Enums\ReasoningEffort;
use App\Models\Agent;
use App\Models\Post;
use App\Models\Topic;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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

    public function mount(?string $folder = null): void
    {
        if (! $this->selectedSystemFolderSlug && $folder && PostFolder::tryFrom($folder)) {
            $this->selectedSystemFolderSlug = $folder;
            $this->mobilePanel = 'posts';
        }

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

    public function selectedSystemFolder(): ?PostFolder
    {
        if ($this->selectedSystemFolderSlug) {
            return PostFolder::tryFrom($this->selectedSystemFolderSlug);
        }

        if ($this->selectedTopicSlug || $this->isCreatingPost()) {
            return null;
        }

        return PostFolder::Feed;
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
            return $this->systemFolderRoute($folder);
        }

        if ($topic = $this->selectedTopic()) {
            return route('dashboard', ['topic' => $topic->slug, 'panel' => 'posts']);
        }

        return route('dashboard');
    }

    public function postsPanelReturnLabel(): string
    {
        if ($folder = $this->selectedSystemFolder()) {
            return $folder->label();
        }

        if ($topic = $this->selectedTopic()) {
            return $topic->name;
        }

        return __('Dashboard');
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function systemFolderRoute(PostFolder $folder, array $parameters = []): string
    {
        return match ($folder) {
            PostFolder::Feed => route('dashboard', $parameters),
            PostFolder::Drafts => route('posts.drafts', $parameters),
            PostFolder::Archived => route('posts.archived', $parameters),
        };
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

    /** @return list<array{slug: string, name: string, icon: string, href: string, count: int}> */
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

        return collect(PostFolder::cases())
            ->map(fn (PostFolder $folder): array => [
                'slug' => $folder->value,
                'name' => $folder->label(),
                'icon' => $folder->icon(),
                'href' => $this->systemFolderRoute($folder),
                'count' => (int) ($counts[$folder->status()->value] ?? 0),
            ])
            ->all();
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
     * @return list<array{href: string, name: string, meta: list<array{key: string, label: string, value: string, title?: string}>, attachments_count: int, badge: array{label: string, color: string}|null}>
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
                'meta' => $post->listMeta(showSender: true, timezone: Auth::user()->displayTimezone()),
                'attachments_count' => $post->attachments_count,
                'sort' => $post->listSortValues(dateKey: PostListColumn::Sent->value),
                'badge' => $post->status === PostStatus::Published ? null : [
                    'label' => $post->status->label(),
                    'color' => $post->status->color(),
                ],
            ])
            ->all();
    }

    /**
     * @return list<array{href: string, name: string, meta: list<array{key: string, label: string, value: string, title?: string}>, attachments_count: int, badge: array{label: string, color: string}|null}>
     */
    public function selectedSystemFolderItems(): array
    {
        $workspace = $this->workspace();
        $folder = $this->selectedSystemFolder();

        if (! $workspace || ! $folder) {
            return [];
        }

        return Post::query()
            ->with(['topic', 'sender.user', 'sender.agent'])
            ->withCount('attachments')
            ->whereHas('topic', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->where('status', $folder->status())
            ->when($folder !== PostFolder::Archived && ! $this->showArchived, fn ($query) => $query->where('status', '!=', PostStatus::Archived))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (Post $post) use ($folder): array {
                $isDraftsFolder = $folder === PostFolder::Drafts;
                $timezone = Auth::user()->displayTimezone();

                $sort = $isDraftsFolder
                    ? [
                        ...$post->listSortValues(dateKey: PostListColumn::Saved->value),
                        PostListColumn::Topic->value => Str::lower($post->topic->name),
                    ]
                    : $post->listSortValues(dateKey: PostListColumn::Sent->value);

                return [
                    'href' => $this->systemFolderRoute($folder, [
                        'topic' => $post->topic->slug,
                        'post' => $post->slug,
                        'panel' => 'posts',
                    ]),
                    'name' => $post->title,
                    'meta' => $post->listTopicMeta(showSender: ! $isDraftsFolder, timezone: $timezone),
                    'attachments_count' => $post->attachments_count,
                    'sort' => $sort,
                    'badge' => null,
                ];
            })
            ->all();
    }

    /**
     * @return list<array{key: string, label: string, class: string}>
     */
    public function selectedPostListColumns(): array
    {
        $folder = $this->selectedSystemFolder();
        $dateLabel = $folder?->dateLabel() ?? __('Posted');

        if ($folder === PostFolder::Drafts) {
            $columns = [
                PostListColumn::Name->toColumn(),
                PostListColumn::Topic->toColumn(),
            ];
        } elseif ($folder) {
            $columns = [
                PostListColumn::Sender->toColumn(),
                PostListColumn::Name->toColumn(),
                PostListColumn::Topic->toColumn(),
            ];
        } else {
            $columns = [
                PostListColumn::Sender->toColumn(),
                PostListColumn::Name->toColumn(),
            ];
        }

        $dateColumn = PostListColumn::from($folder?->dateKey() ?? PostListColumn::Sent->value);
        $columns[] = $dateColumn->toColumn($dateLabel);
        $columns[] = PostListColumn::Attachments->toColumn();

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
            'provider' => ['required', 'string', Rule::enum(Provider::class)],
            'model' => ['required', 'string', 'max:255'],
            'reasoningEffort' => ['nullable', 'string', Rule::enum(ReasoningEffort::class)],
            'prompt' => ['nullable', 'string'],
        ]);

        app(CreateAgent::class)->handle(
            workspace: $workspace,
            name: $validated['agentName'],
            provider: $validated['provider'],
            model: $validated['model'],
            reasoningEffort: $validated['reasoningEffort'],
            prompt: $validated['prompt'],
        );

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
        $uploads = $this->newPostUploads;

        $validated = $this->validate([
            'newPostTitle' => ['required', 'string', 'max:255'],
            'newPostBody' => ['nullable', 'string'],
            'newPostTopicId' => ['required', 'integer'],
            'newPostAgentIds' => ['array'],
            'newPostAgentIds.*' => ['integer'],
        ], [], [
            'newPostTitle' => __('title'),
            'newPostBody' => __('body'),
            'newPostTopicId' => __('topic'),
            'newPostAgentIds' => __('requested agents'),
        ]);
        Validator::make(['newPostUploads' => $uploads], [
            'newPostUploads.*' => ['file', 'max:51200'],
        ], [], [
            'newPostUploads.*' => __('attachment'),
        ])->validate();

        $topic = $workspace->topics()->findOrFail($validated['newPostTopicId']);
        $post = app(CreatePost::class)->handle(
            topic: $topic,
            sender: $workspace->principalForUser(Auth::user()),
            title: $validated['newPostTitle'],
            body: $validated['newPostBody'],
            status: $status,
            agentIds: $validated['newPostAgentIds'],
            uploads: $uploads,
        );

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
            'selectedAgentProvider' => ['required', 'string', Rule::enum(Provider::class)],
            'selectedAgentModel' => ['required', 'string', 'max:255'],
            'selectedAgentReasoningEffort' => ['nullable', 'string', Rule::enum(ReasoningEffort::class)],
            'selectedAgentPrompt' => ['nullable', 'string'],
        ]);

        app(CreateAgentVersion::class)->handle(
            agent: $agent,
            provider: $validated['selectedAgentProvider'],
            model: $validated['selectedAgentModel'],
            reasoningEffort: $validated['selectedAgentReasoningEffort'],
            prompt: $validated['selectedAgentPrompt'],
        );

        Flux::toast(variant: 'success', text: __('Version saved.'));
    }

    public function saveSelectedPost(): void
    {
        $post = $this->selectedPost();

        abort_unless($post && $post->status === PostStatus::Draft, 403);

        $workspace = $this->workspace();

        abort_unless($workspace, 403);

        $uploads = $this->postUploads;

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
        Validator::make(['postUploads' => $uploads], [
            'postUploads.*' => ['file', 'max:51200'],
        ], [], [
            'postUploads.*' => __('attachment'),
        ])->validate();

        $post = app(UpdateDraftPost::class)->handle(
            post: $post,
            workspace: $workspace,
            user: Auth::user(),
            title: $validated['postTitle'],
            body: $validated['postBody'],
            agentIds: $validated['postAgentIds'],
            uploads: $uploads,
        );
        $this->reset('postUploads');

        $this->selectedPostSlug = $post->slug;

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function publishSelectedPost(): void
    {
        $post = $this->selectedPost();

        abort_unless($post && $post->status === PostStatus::Draft, 403);

        $workspace = $this->workspace();

        abort_unless($workspace, 403);

        $this->saveSelectedPost();

        app(UpdateDraftPost::class)->handle(
            post: $post->fresh(),
            workspace: $workspace,
            user: Auth::user(),
            title: $this->postTitle,
            body: $this->postBody,
            agentIds: $this->postAgentIds,
            uploads: [],
            publish: true,
        );
    }

    public function deleteSelectedPostAttachment(int $attachmentId): void
    {
        $post = $this->selectedPost();

        abort_unless($post && $post->status === PostStatus::Draft, 403);

        app(DeletePostAttachment::class)->handle($post, $attachmentId);

        Flux::toast(variant: 'success', text: __('Attachment deleted.'));
    }

    public function archiveSelectedPost(): void
    {
        $post = $this->selectedPost();

        abort_unless($post, 404);

        $post->archive();
    }

    public function unpublishSelectedPost(): void
    {
        $post = $this->selectedPost();

        abort_unless($post && $post->status === PostStatus::Published, 403);

        $post->moveToDraft();

        $this->syncSelectedPostFields();
    }

    public function unarchiveSelectedPost(): void
    {
        $post = $this->selectedPost();

        abort_unless($post && $post->status === PostStatus::Archived, 403);

        $post->moveToDraft();

        $this->syncSelectedPostFields();
    }

    private function syncSelectedPostFields(): void
    {
        $post = $this->selectedPost();

        $this->postTitle = $post?->title ?? '';
        $this->postBody = $post?->body ?? '';
        $this->postAgentIds = $post?->assignedAgentIds() ?? [];
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
                        @php
                            $selectedFolder = $this->selectedSystemFolder();
                        @endphp
                        @foreach ($this->systemFolders() as $folder)
                            <a href="{{ $folder['href'] }}" wire:navigate
                               @class([
                                   'flex items-center gap-3 px-4 py-3 hover:bg-neutral-100 dark:hover:bg-white/5',
                                   'bg-blue-100/80 dark:bg-blue-500/15' => $selectedFolder?->value === $folder['slug'],
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
                                        <flux:badge color="green" size="sm" title="{{ __('Feed') }}" data-test="topic-{{ $topic->slug }}-published-count" data-count="{{ $topic->published_count }}">{{ $topic->published_count }}</flux:badge>
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

                        @include('partials.post-draft-form', [
                            'formId' => 'dashboard-new-post-form',
                            'submitAction' => 'createDashboardPost',
                            'titleModel' => 'newPostTitle',
                            'titleTest' => 'new-post-title',
                            'bodyModel' => 'newPostBody',
                            'bodyTest' => 'new-post-body',
                            'topicModel' => 'newPostTopicId',
                            'agentIdsModel' => 'newPostAgentIds',
                            'availableTopics' => $this->availableTopics,
                            'availableAgents' => $this->newPostAssignableAgents(),
                            'canChangeTopic' => true,
                            'testPrefix' => 'new-post',
                            'uploadModel' => 'newPostUploads',
                            'uploadError' => 'newPostUploads.*',
                            'returnHref' => $this->postsPanelReturnRoute(),
                            'saveTest' => 'new-post-save-draft',
                            'publishAction' => 'sendDashboardPost',
                            'publishTest' => 'new-post-send',
                            'loadingTarget' => 'newPostUploads',
                            'dataTest' => 'dashboard-post-create-panel',
                        ])
                    @elseif ($selectedDashboardPost)
                        <div class="flex items-center justify-between gap-3 border-b border-neutral-300 bg-emerald-50 px-4 py-3 dark:border-white/10 dark:bg-emerald-500/10">
                            <flux:heading size="sm" class="min-w-0 flex-1 truncate">{{ $selectedDashboardPost->title }}</flux:heading>

                            <flux:button :href="$this->postsPanelReturnRoute()" wire:navigate size="xs" variant="filled" icon="arrow-left" data-test="posts-panel-return">
                                {{ $this->postsPanelReturnLabel() }}
                            </flux:button>
                        </div>

                        @if ($selectedDashboardPost->status === PostStatus::Draft)
                            @include('partials.post-draft-form', [
                                'formId' => 'dashboard-selected-post-form',
                                'submitAction' => 'saveSelectedPost',
                                'titleModel' => 'postTitle',
                                'bodyModel' => 'postBody',
                                'topicName' => $selectedDashboardPost->topic->name,
                                'agentIdsModel' => 'postAgentIds',
                                'availableAgents' => $this->selectedPostAssignableAgents(),
                                'canChangeTopic' => false,
                                'testPrefix' => 'post',
                                'post' => $selectedDashboardPost,
                                'uploadModel' => 'postUploads',
                                'uploadError' => 'postUploads.*',
                                'deleteAction' => 'deleteSelectedPostAttachment',
                                'archiveAction' => 'archiveSelectedPost',
                                'publishAction' => 'publishSelectedPost',
                                'loadingTarget' => 'postUploads',
                                'dataTest' => 'dashboard-post-panel',
                            ])
                        @else
                            <div class="flex flex-1 flex-col gap-6 overflow-auto px-4 py-4 xl:min-h-0" data-test="dashboard-post-panel">
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
                                        <flux:badge color="zinc" size="sm">{{ __('Sender') }}: {{ $selectedDashboardPost->sender->label() }}</flux:badge>
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
                                'uploadModel' => 'postUploads',
                                'uploadError' => 'postUploads.*',
                                'deleteAction' => 'deleteSelectedPostAttachment',
                                ])
                            </div>
                        @endif
                    @else
                        @include('partials.folder-view', [
                            'breadcrumbs' => [
                                ['label' => $this->workspace()->name, 'href' => route('dashboard')],
                                ['label' => $selectedDashboardFolder?->label() ?? $this->selectedTopic()?->name ?? __('New post')],
                            ],
                            'titleLabel' => $selectedDashboardFolder?->label() ?? $this->selectedTopic()?->name ?? __('Feed'),
                            'items' => collect($selectedDashboardFolder ? $this->selectedSystemFolderItems() : $this->selectedTopicItems()),
                            'icon' => 'document-text',
                            'iconClass' => 'size-12 text-neutral-400 group-hover:text-neutral-300',
                            'emptyText' => __('No posts'),
                            'createHref' => $this->selectedTopic() ? route('posts.create', ['topic' => $this->selectedTopic()->slug]) : route('posts.create'),
                            'createLabel' => __('New post'),
                            'createTest' => 'dashboard-new-post-button',
                            'showArchivedModel' => 'showArchived',
                            'listColumns' => $this->selectedPostListColumns(),
                            'listDefaultSort' => $selectedDashboardFolder?->dateKey() ?? PostListColumn::Sent->value,
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
                        <flux:heading size="sm">{{ __('Feed') }}</flux:heading>
                        <flux:button :href="route('posts.create')" wire:navigate size="xs" icon="plus" data-test="dashboard-new-post-button">
                            {{ __('New post') }}
                        </flux:button>
                    </div>

                    <div class="flex flex-1 items-center justify-center px-6 py-10 text-center">
                        <div class="space-y-2">
                            <flux:heading size="sm">{{ __('Select a topic') }}</flux:heading>
                            <flux:text class="text-sm text-neutral-400 dark:text-neutral-600">
                                {{ __('Choose a topic to view its feed.') }}
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
                                                <x-provider-options />
                                            </flux:select>

                                            <flux:select wire:model="selectedAgentModel" :label="__('Model')" placeholder="{{ __('Select model…') }}" :disabled="!$selectedAgentProvider" required>
                                                @foreach ($this->selectedAgentAvailableModels as $availableModel)
                                                    <flux:select.option :value="$availableModel">{{ $availableModel }}</flux:select.option>
                                                @endforeach
                                            </flux:select>
                                        </div>

                                        @if ($this->selectedAgentShowReasoningEffort)
                                            <flux:select wire:model="selectedAgentReasoningEffort" :label="__('Reasoning effort')" placeholder="{{ __('Select effort…') }}">
                                                <x-reasoning-effort-options />
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
                    <span>{{ __('Feed') }}</span>
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
                        <x-provider-options />
                    </flux:select>

                    <flux:select wire:model="model" :label="__('Model')" placeholder="{{ __('Select model…') }}" :disabled="!$provider" required>
                        @foreach ($this->availableModels as $availableModel)
                            <flux:select.option :value="$availableModel">{{ $availableModel }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                @if ($this->showReasoningEffort)
                    <flux:select wire:model="reasoningEffort" :label="__('Reasoning effort')" placeholder="{{ __('Select effort…') }}">
                        <x-reasoning-effort-options />
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
