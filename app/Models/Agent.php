<?php

namespace App\Models;

use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable(['workspace_id', 'name', 'slug'])]
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory, SoftDeletes;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Agent $agent) {
            if (empty($agent->slug)) {
                $agent->slug = static::generateUniqueSlug($agent->workspace_id, $agent->name);
            }
        });

        static::updating(function (Agent $agent) {
            if ($agent->isDirty('name')) {
                $agent->slug = static::generateUniqueSlug($agent->workspace_id, $agent->name, $agent->id);
            }
        });
    }

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
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<AgentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AgentVersion::class)->orderBy('version');
    }

    /**
     * @return HasMany<AgentTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(AgentTask::class);
    }

    /**
     * @return HasMany<ThreadAgentState, $this>
     */
    public function threadStates(): HasMany
    {
        return $this->hasMany(ThreadAgentState::class);
    }

    /**
     * @return HasOne<AgentVersion, $this>
     */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(AgentVersion::class)->latestOfMany('version');
    }

    /**
     * @return HasOne<Principal, $this>
     */
    public function principal(): HasOne
    {
        return $this->hasOne(Principal::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
