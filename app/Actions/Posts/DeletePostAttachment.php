<?php

namespace App\Actions\Posts;

use App\Models\Post;

class DeletePostAttachment
{
    public function handle(Post $post, int $attachmentId): void
    {
        $attachment = $post->attachments()->findOrFail($attachmentId);

        $post->loadMissing('topic.workspace');
        $post->topic->workspace->filesystem()->delete($attachment->path);

        $attachment->delete();
    }
}
