<?php

namespace App\Actions\Posts;

use App\Models\Post;
use Illuminate\Support\Facades\Storage;

class DeletePostAttachment
{
    public function handle(Post $post, int $attachmentId): void
    {
        $attachment = $post->attachments()->findOrFail($attachmentId);

        Storage::disk('public')->delete($attachment->path);

        $attachment->delete();
    }
}
