<?php

use App\Enums\PostStatus;
use App\Actions\Posts\UpdateDraftPost;
use App\Models\Agent;
use App\Models\Post;
use App\Models\Topic;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::workspace'), Title('Post')] class extends Component {
    use WithFileUploads;

    public Topic $topic;

    public Post $post;

    public string $title = '';

    public string $body = '';

    /** @var list<int> */
    public array $agentIds = [];

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

    public function mount(Post $post): void
    {
        $topic = $post->topic;

        abort_unless(
            Auth::user()->currentWorkspace?->id === $topic->workspace_id,
            403
        );

        $this->topic = $topic;
        $this->title = $post->title;
        $this->body = $post->body ?? '';
        $this->agentIds = $post->agentTasks()
            ->where('event_type', \App\Models\AgentTask::EventPostAssigned)
            ->pluck('agent_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Agent>
     */
    #[Computed]
    public function availableAgents(): \Illuminate\Database\Eloquent\Collection
    {
        $workspace = Auth::user()->currentWorkspace;

        if (! $workspace) {
            return Agent::query()->whereNull('id')->get();
        }

        return $this->topic->agents()->get();
    }

    public function save(): void
    {
        abort_unless($this->post->status === PostStatus::Draft, 403);

        $workspace = Auth::user()->currentWorkspace;

        abort_unless($workspace, 403);

        $uploads = $this->uploads;

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'agentIds' => ['array'],
            'agentIds.*' => ['integer'],
        ], [], [
            'agentIds' => __('requested agents'),
        ]);
        Validator::make(['uploads' => $uploads], [
            'uploads.*' => ['file', 'max:51200'],
        ], [], [
            'uploads.*' => __('attachment'),
        ])->validate();

        $this->post = app(UpdateDraftPost::class)->handle(
            post: $this->post,
            workspace: $workspace,
            user: Auth::user(),
            title: $validated['title'],
            body: $validated['body'],
            agentIds: $validated['agentIds'],
            uploads: $uploads,
        );
        $this->reset('uploads');

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function publish(): void
    {
        abort_unless($this->post->status === PostStatus::Draft, 403);

        $workspace = Auth::user()->currentWorkspace;

        abort_unless($workspace, 403);

        $uploads = $this->uploads;

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'agentIds' => ['array'],
            'agentIds.*' => ['integer'],
        ], [], [
            'agentIds' => __('requested agents'),
        ]);
        Validator::make(['uploads' => $uploads], [
            'uploads.*' => ['file', 'max:51200'],
        ], [], [
            'uploads.*' => __('attachment'),
        ])->validate();

        $this->post = app(UpdateDraftPost::class)->handle(
            post: $this->post,
            workspace: $workspace,
            user: Auth::user(),
            title: $validated['title'],
            body: $validated['body'],
            agentIds: $validated['agentIds'],
            uploads: $uploads,
            publish: true,
        );
        $this->reset('uploads');
    }

    public function unpublish(): void
    {
        $this->post->update(['status' => PostStatus::Draft]);

        $this->title = $this->post->title;
        $this->body = $this->post->body ?? '';
        $this->agentIds = $this->post->agentTasks()
            ->where('event_type', \App\Models\AgentTask::EventPostAssigned)
            ->pluck('agent_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function archive(): void
    {
        $this->post->update(['status' => PostStatus::Archived]);
    }

    public function unarchive(): void
    {
        $this->post->update(['status' => PostStatus::Draft]);

        $this->title = $this->post->title;
        $this->body = $this->post->body ?? '';
        $this->agentIds = $this->post->agentTasks()
            ->where('event_type', \App\Models\AgentTask::EventPostAssigned)
            ->pluck('agent_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function deleteAttachment(int $attachmentId): void
    {
        abort_unless($this->post->status === PostStatus::Draft, 403);

        $attachment = $this->post->attachments()->findOrFail($attachmentId);

        Storage::disk('public')->delete($attachment->path);

        $attachment->delete();

        Flux::toast(variant: 'success', text: __('Attachment deleted.'));
    }

}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-3 xl:flex-1">
    <section class="flex min-h-[calc(100dvh-4rem)] flex-col overflow-hidden rounded-xl border border-neutral-300 bg-white shadow-sm shadow-black/[0.04] xl:h-full xl:min-h-[24rem] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none" data-test="post-panel">
        <div class="flex items-center justify-between gap-3 border-b border-neutral-300 bg-emerald-50 px-4 py-3 dark:border-white/10 dark:bg-emerald-500/10">
            <flux:heading size="sm" class="min-w-0 flex-1 truncate">{{ $post->title }}</flux:heading>
        </div>

        @if ($post->status === App\Enums\PostStatus::Draft)
            @include('partials.post-draft-form', [
                'formId' => 'post-form',
                'submitAction' => 'save',
                'titleModel' => 'title',
                'bodyModel' => 'body',
                'topicName' => $topic->name,
                'agentIdsModel' => 'agentIds',
                'availableAgents' => $this->availableAgents,
                'canChangeTopic' => false,
                'testPrefix' => 'post',
                'post' => $post,
                'uploadModel' => 'uploads',
                'uploadError' => 'uploads.*',
                'deleteAction' => 'deleteAttachment',
                'archiveAction' => 'archive',
                'publishAction' => 'publish',
                'loadingTarget' => 'uploads',
            ])
        @else
            <div class="flex flex-1 flex-col gap-6 overflow-auto px-4 py-4 xl:min-h-0">
                {{-- Non-draft posts are read-only. --}}
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <flux:heading size="xl" class="min-w-0 flex-1 truncate">{{ $post->title }}</flux:heading>

                    <div class="flex shrink-0 items-center gap-2">
                        @if ($post->status === App\Enums\PostStatus::Published)
                            <flux:button wire:click="unpublish" size="sm" icon="pencil-square" icon:variant="outline">{{ __('Move to drafts') }}</flux:button>
                            <flux:button wire:click="archive" size="sm" icon="archive-box" icon:variant="outline">{{ __('Archive') }}</flux:button>
                        @elseif ($post->status === App\Enums\PostStatus::Archived)
                            <flux:badge :color="$post->status->color()" size="sm">{{ $post->status->label() }}</flux:badge>
                            <flux:button wire:click="unarchive" size="sm" icon="archive-box-x-mark">{{ __('Unarchive') }}</flux:button>
                        @endif
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    @if ($post->sender)
                        <flux:badge color="zinc" size="sm">{{ __('Sender') }}: {{ $post->sender->label() }}</flux:badge>
                    @endif

                    <flux:badge color="zinc" size="sm">
                        {{ __('Topic') }}:
                        {{ $topic->name }}
                    </flux:badge>

                    @foreach ($post->assignedAgents as $agent)
                        <flux:badge color="amber" size="sm">{{ __('Agent work') }}: {{ $agent->name }}</flux:badge>
                    @endforeach
                </div>

                <div>
                    @if ($post->body)
                        <flux:text class="whitespace-pre-wrap text-sm leading-relaxed text-neutral-700 dark:text-neutral-300">{{ $post->body }}</flux:text>
                    @else
                        <flux:text class="text-sm text-neutral-400 dark:text-neutral-600">{{ __('No content.') }}</flux:text>
                    @endif
                </div>

                @include('partials.post-attachments', [
                    'post' => $post,
                    'uploadModel' => 'uploads',
                    'uploadError' => 'uploads.*',
                    'deleteAction' => 'deleteAttachment',
                ])
            </div>
        @endif
    </section>
</div>
