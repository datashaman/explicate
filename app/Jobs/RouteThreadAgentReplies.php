<?php

namespace App\Jobs;

use App\Actions\Agents\SyncAgentChatReplies;
use App\Models\Post;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RouteThreadAgentReplies implements ShouldQueue
{
    use Queueable;

    public function __construct(public Post $post) {}

    /**
     * Execute the job.
     */
    public function handle(SyncAgentChatReplies $syncAgentChatReplies): void
    {
        $syncAgentChatReplies->route($this->post);
    }
}
