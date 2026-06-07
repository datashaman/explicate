<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttachmentController extends Controller
{
    public function __invoke(Attachment $attachment): BinaryFileResponse
    {
        $attachment->loadMissing('post.topic');

        abort_unless(
            Auth::user()->currentWorkspace?->id === $attachment->post->topic->workspace_id,
            404
        );

        abort_unless(Storage::disk('public')->exists($attachment->path), 404);

        return response()->file(Storage::disk('public')->path($attachment->path), [
            'Content-Type' => $attachment->mime_type ?? 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$attachment->filename.'"',
        ]);
    }
}
