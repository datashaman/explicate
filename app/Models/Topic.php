<?php

namespace App\Models;

use Database\Factories\TopicFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable(['workspace_id', 'name', 'slug'])]
class Topic extends Model
{
    /** @use HasFactory<TopicFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Topic $topic) {
            if (empty($topic->slug)) {
                $topic->slug = static::generateUniqueSlug($topic->workspace_id, $topic->name);
            }
        });

        static::updating(function (Topic $topic) {
            if ($topic->isDirty('name')) {
                $topic->slug = static::generateUniqueSlug($topic->workspace_id, $topic->name, $topic->id);
            }
        });
    }

    /**
     * Generate a unique slug for the topic within a workspace.
     */
    protected static function generateUniqueSlug(int $workspaceId, string $name, ?int $excludeId = null): string
    {
        $base = Str::slug($name);

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
     * @return BelongsToMany<Agent, $this>
     */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class)->withPivot('agent_version_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('title');
    }

    /**
     * @return HasMany<Thread, $this>
     */
    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class)->orderByDesc('updated_at');
    }

    /**
     * Get the workspace this topic belongs to.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
