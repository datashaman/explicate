<?php

namespace App\Actions\Threads;

use App\Actions\Posts\CreatePost;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\Principal;
use App\Models\Thread;
use App\Models\Topic;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class StartConversation
{
    public function __construct(private CreatePost $createPost) {}

    /**
     * @param  array<int, TemporaryUploadedFile>  $uploads
     */
    public function handle(
        Workspace $workspace,
        Principal $sender,
        string $body,
        PostStatus $status,
        ?Topic $topic = null,
        ?string $title = null,
        array $uploads = [],
    ): Post {
        return DB::transaction(function () use ($workspace, $sender, $body, $status, $topic, $title, $uploads): Post {
            $thread = Thread::create([
                'workspace_id' => $workspace->id,
                'topic_id' => $topic?->id,
                'title' => $title ?: $this->titleFromBody($body),
            ]);

            return $this->createPost->handle(
                thread: $thread,
                sender: $sender,
                body: $body,
                status: $status,
                uploads: $uploads,
            );
        });
    }

    private function titleFromBody(string $body): string
    {
        return Str::of($body)
            ->squish()
            ->limit(80, '')
            ->whenEmpty(fn () => __('Untitled conversation'))
            ->toString();
    }
}
