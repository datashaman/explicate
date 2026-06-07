<?php

use App\Actions\Posts\DeletePostAttachment;
use App\Actions\Posts\UpdateDraftPost;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\Topic;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
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

    public string $body = '';

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
        $this->post = $post->loadMissing(['agentTasks.agent', 'attachments', 'sender.user', 'sender.agent', 'topic']);
        $this->body = $post->body ?? '';
    }

    public function save(): void
    {
        abort_unless($this->post->status === PostStatus::Draft, 403);

        $workspace = Auth::user()->currentWorkspace;

        abort_unless($workspace, 403);

        $uploads = $this->uploads;

        $validated = $this->validate([
            'body' => ['required', 'string'],
        ], [], [
            'body' => __('post'),
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
            body: $validated['body'],
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
            'body' => ['required', 'string'],
        ], [], [
            'body' => __('post'),
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
            body: $validated['body'],
            uploads: $uploads,
            publish: true,
        );
        $this->reset('uploads');
    }

    public function unpublish(): void
    {
        $this->post->moveToDraft();

        $this->body = $this->post->body ?? '';
    }

    public function archive(): void
    {
        $this->post->archive();
    }

    public function unarchive(): void
    {
        $this->post->moveToDraft();

        $this->body = $this->post->body ?? '';
    }

    public function deleteAttachment(int $attachmentId): void
    {
        abort_unless($this->post->status === PostStatus::Draft, 403);

        app(DeletePostAttachment::class)->handle($this->post, $attachmentId);

        Flux::toast(variant: 'success', text: __('Attachment deleted.'));
    }

}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-3 xl:flex-1">
    <section class="flex min-h-[calc(100dvh-4rem)] flex-col overflow-hidden rounded-xl border border-neutral-300 bg-white shadow-sm shadow-black/[0.04] xl:h-full xl:min-h-[24rem] dark:border-white/10 dark:bg-zinc-900/40 dark:shadow-none" data-test="post-panel">
        <div class="flex items-center justify-between gap-3 border-b border-neutral-300 bg-emerald-50 px-4 py-3 dark:border-white/10 dark:bg-emerald-500/10">
            <flux:heading size="sm" class="min-w-0 flex-1 truncate">{{ __('Post') }}</flux:heading>
        </div>

        @if ($post->status === App\Enums\PostStatus::Draft)
            @include('partials.post-draft-form', [
                'formId' => 'post-form',
                'submitAction' => 'save',
                'bodyModel' => 'body',
                'topicName' => $topic->name,
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
                @php
                    $threadPosts = $post->conversationPosts();
                @endphp

                @foreach ($threadPosts as $threadPost)
                    <x-post-message :post="$threadPost">
                        @if ($threadPost->is($post))
                            <x-slot:actions>
                                @if ($post->status === App\Enums\PostStatus::Published)
                                    <flux:menu.item wire:click="unpublish" icon="pencil-square">{{ __('Move to drafts') }}</flux:menu.item>
                                    <flux:menu.item wire:click="archive" icon="archive-box">{{ __('Archive') }}</flux:menu.item>
                                @elseif ($post->status === App\Enums\PostStatus::Archived)
                                    <flux:menu.item wire:click="unarchive" icon="archive-box-x-mark">{{ __('Unarchive') }}</flux:menu.item>
                                @endif
                            </x-slot:actions>
                        @endif
                    </x-post-message>
                @endforeach

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
