<?php

namespace App\Models;

use App\Enums\AgentTaskStatus;
use App\Enums\PostStatus;
use Database\Factories\AgentTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['agent_id', 'post_id', 'status_post_id', 'event_type', 'status', 'priority', 'available_at', 'locked_at', 'attempts', 'last_error'])]
class AgentTask extends Model
{
    public const string EventChatSummoned = 'chat_summoned';

    public const string EventThreadRouted = 'thread_routed';

    public const string EventPostMentioned = self::EventChatSummoned;

    /** @use HasFactory<AgentTaskFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (AgentTask $task): void {
            $task->statusPost?->delete();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AgentTaskStatus::class,
            'available_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function statusPost(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'status_post_id');
    }

    public function thread(): Thread
    {
        $this->loadMissing(['post.thread', 'post.startedThread']);

        if ($this->post->thread) {
            return $this->post->thread;
        }

        return $this->post->startedThread()->firstOrCreate([], [
            'topic_id' => $this->post->topic_id,
            'title' => $this->post->preview(),
        ]);
    }

    public function syncStatusPost(?string $body = null): Post
    {
        $this->loadMissing(['agent.workspace', 'post.topic', 'statusPost']);

        $thread = $this->thread();
        $sender = $this->agent->workspace->principalForAgent($this->agent);
        $postBody = $body ?? $this->statusPostBody();

        $post = $this->statusPost;

        if (! $post) {
            $post = $thread->posts()->create([
                'topic_id' => $this->post->topic_id,
                'sender_principal_id' => $sender->id,
                'body' => $postBody,
                'status' => PostStatus::Published,
            ]);

            $this->forceFill(['status_post_id' => $post->id])->save();
            $this->setRelation('statusPost', $post);

            return $post;
        }

        $post->forceFill([
            'topic_id' => $this->post->topic_id,
            'thread_id' => $thread->id,
            'sender_principal_id' => $sender->id,
            'body' => $postBody,
            'status' => PostStatus::Published,
        ])->save();

        return $post;
    }

    private function statusPostBody(): string
    {
        $agentName = $this->agent->name;

        return match ($this->status) {
            AgentTaskStatus::Pending => __(':agent queued.', ['agent' => $agentName]),
            AgentTaskStatus::Processing => __(':agent is working.', ['agent' => $agentName]),
            AgentTaskStatus::Completed => __(':agent replied.', ['agent' => $agentName]),
            AgentTaskStatus::Failed => __(':agent failed: :error', [
                'agent' => $agentName,
                'error' => $this->last_error ?: __('Unknown error.'),
            ]),
        };
    }
}
