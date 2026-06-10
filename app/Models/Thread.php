<?php

namespace App\Models;

use Database\Factories\ThreadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Fillable(['workspace_id', 'topic_id', 'title', 'slug', 'summary'])]
class Thread extends Model
{
    /** @use HasFactory<ThreadFactory> */
    use HasFactory, SoftDeletes;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Thread $thread) {
            if (empty($thread->slug)) {
                $thread->slug = static::generateUniqueSlug($thread->workspace_id, $thread->title);
            }
        });

        static::updating(function (Thread $thread) {
            if ($thread->isDirty('title')) {
                $thread->slug = static::generateUniqueSlug($thread->workspace_id, $thread->title, $thread->id);
            }
        });
    }

    protected static function generateUniqueSlug(int $workspaceId, string $title, ?int $excludeId = null): string
    {
        $base = Str::slug($title) ?: 'thread';

        $query = static::withTrashed()
            ->where('workspace_id', $workspaceId)
            ->where(function ($q) use ($base) {
                $q->where('slug', $base)->orWhere('slug', 'like', $base.'-%');
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $existing = $query->pluck('slug');

        $max = $existing
            ->map(function (string $slug) use ($base): ?int {
                if ($slug === $base) {
                    return 0;
                } elseif (preg_match('/^'.preg_quote($base, '/').'-(\d+)$/', $slug, $m)) {
                    return (int) $m[1];
                }

                return null;
            })
            ->filter(fn (?int $n) => $n !== null)
            ->max() ?? 0;

        return $existing->isEmpty() ? $base : $base.'-'.($max + 1);
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class)->orderBy('id');
    }

    /**
     * @return Collection<int, Post>
     */
    public function conversationPosts(): Collection
    {
        return $this->posts()
            ->with(['agentTasks.agent', 'attachments', 'sender.user', 'sender.agent'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return HasOne<Post, $this>
     */
    public function firstPost(): HasOne
    {
        return $this->hasOne(Post::class)->oldestOfMany();
    }

    /**
     * @return HasOne<Post, $this>
     */
    public function latestPost(): HasOne
    {
        return $this->hasOne(Post::class)->latestOfMany();
    }

    /**
     * @return HasMany<ThreadAgentState, $this>
     */
    public function agentStates(): HasMany
    {
        return $this->hasMany(ThreadAgentState::class);
    }

    public function agentStateFor(Agent $agent): ThreadAgentState
    {
        return $this->agentStates()->firstOrCreate([
            'agent_id' => $agent->id,
        ], [
            'task_list' => [],
        ]);
    }

    /**
     * @return BelongsTo<Topic, $this>
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
