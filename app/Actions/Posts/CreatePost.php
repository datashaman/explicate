<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\Principal;
use App\Models\Thread;
use App\Models\Topic;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class CreatePost
{
    public function __construct(private StorePostAttachments $storePostAttachments) {}

    /**
     * @param  array<int, TemporaryUploadedFile>  $uploads
     */
    public function handle(
        Topic $topic,
        Principal $sender,
        string $body,
        PostStatus $status,
        ?Thread $thread = null,
        array $uploads = [],
    ): Post {
        $post = $topic->posts()->create([
            'body' => $body,
            'status' => $status,
            'thread_id' => $thread?->id,
            'sender_principal_id' => $sender->id,
        ]);

        $this->storePostAttachments->handle($post, $uploads);

        return $post;
    }
}
