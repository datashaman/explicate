<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttachmentController extends Controller
{
    public function __invoke(Attachment $attachment): BinaryFileResponse
    {
        $attachment->loadMissing('post.topic.workspace');

        abort_unless(
            Auth::user()->currentWorkspace?->id === $attachment->post->topic->workspace_id,
            404
        );

        $filesystem = $attachment->post->topic->workspace->filesystem();

        abort_unless($filesystem->exists($attachment->path), 404);

        return response()->file($filesystem->path($attachment->path), [
            'Content-Type' => $attachment->mime_type ?? 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$attachment->filename.'"',
        ]);
    }
}
