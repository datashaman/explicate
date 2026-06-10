<?php

namespace App\Actions\Posts;

use App\Models\Post;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class StorePostAttachments
{
    /**
     * @param  array<int, TemporaryUploadedFile>  $uploads
     */
    public function handle(Post $post, array $uploads): void
    {
        if (empty($uploads)) {
            return;
        }

        $post->loadMissing('thread.workspace');
        $filesystem = $post->thread->workspace->filesystem();
        $metadata = $this->metadata($uploads);

        foreach ($uploads as $index => $upload) {
            $attachmentMetadata = $metadata[$index];
            $path = 'attachments/'.Str::uuid().'/'.$attachmentMetadata['filename'];

            $filesystem->write($path, file_get_contents($upload->getRealPath()));

            $post->attachments()->create([
                'filename' => $attachmentMetadata['filename'],
                'path' => $path,
                'mime_type' => $attachmentMetadata['mime_type'],
                'size' => $attachmentMetadata['size'],
            ]);
        }
    }

    /**
     * @param  array<int, TemporaryUploadedFile>  $uploads
     * @return array<int, array{filename: string, mime_type: string|null, size: int|null}>
     */
    private function metadata(array $uploads): array
    {
        return array_map(fn (TemporaryUploadedFile $upload): array => [
            'filename' => $upload->getClientOriginalName(),
            'mime_type' => $upload->getMimeType(),
            'size' => $upload->getSize(),
        ], $uploads);
    }
}
