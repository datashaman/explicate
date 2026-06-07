<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Models\Agent;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class UpdateDraftPost
{
    public function __construct(private StorePostAttachments $storePostAttachments) {}

    /**
     * @param  iterable<int, int|string|Agent>  $agentIds
     * @param  array<int, TemporaryUploadedFile>  $uploads
     */
    public function handle(
        Post $post,
        Workspace $workspace,
        User $user,
        string $body,
        iterable $agentIds,
        array $uploads,
        bool $publish = false,
    ): Post {
        $attributes = [
            'body' => $body,
        ];

        if ($publish) {
            $attributes['sender_principal_id'] = $post->sender_principal_id ?: $workspace->principalForUser($user)->id;
            $attributes['status'] = PostStatus::Published;
        }

        $post->update($attributes);
        $post->assignAgents($agentIds);
        $this->storePostAttachments->handle($post, $uploads);

        return $post->fresh();
    }
}
