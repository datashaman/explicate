<?php

namespace App\Models;

use Database\Factories\ThreadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable(['topic_id', 'title', 'slug', 'summary'])]
class Thread extends Model
{
    /** @use HasFactory<ThreadFactory> */
    use HasFactory, SoftDeletes;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Thread $thread) {
            if (empty($thread->slug)) {
                $thread->slug = static::generateUniqueSlug($thread->topic_id, $thread->title);
            }
        });

        static::updating(function (Thread $thread) {
            if ($thread->isDirty('title')) {
                $thread->slug = static::generateUniqueSlug($thread->topic_id, $thread->title, $thread->id);
            }
        });
    }

    protected static function generateUniqueSlug(int $topicId, string $title, ?int $excludeId = null): string
    {
        $base = Str::slug($title);

        $query = static::withTrashed()
            ->where('topic_id', $topicId)
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
        return $this->hasMany(Post::class)->orderBy('created_at');
    }

    /**
     * @return BelongsTo<Topic, $this>
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }
}
