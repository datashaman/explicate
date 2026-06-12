<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueWorkspaceSlugs;
use App\Services\WorkspaceFilesystemService;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use LogicException;

#[Fillable(['team_id', 'name', 'slug'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use GeneratesUniqueWorkspaceSlugs, HasFactory, SoftDeletes;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Workspace $workspace) {
            if (empty($workspace->slug)) {
                $workspace->slug = static::generateUniqueWorkspaceSlug($workspace->team_id, $workspace->name);
            }
        });

        static::updating(function (Workspace $workspace) {
            if ($workspace->isDirty('name')) {
                $workspace->slug = static::generateUniqueWorkspaceSlug($workspace->team_id, $workspace->name, $workspace->id);
            }
        });
    }

    /**
     * @return HasMany<Topic, $this>
     */
    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class)->orderBy('name');
    }

    /**
     * @return HasMany<Thread, $this>
     */
    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class)->orderByDesc('updated_at');
    }

    /**
     * @return HasMany<Brief, $this>
     */
    public function briefs(): HasMany
    {
        return $this->hasMany(Brief::class)->orderByDesc('updated_at');
    }

    /**
     * @return HasManyThrough<Post, Thread, $this>
     */
    public function posts(): HasManyThrough
    {
        return $this->hasManyThrough(Post::class, Thread::class);
    }

    /**
     * @return HasMany<Agent, $this>
     */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class)->orderBy('name');
    }

    /**
     * @return HasMany<WorkspaceRepository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(WorkspaceRepository::class)->orderBy('name');
    }

    /**
     * @return HasMany<Principal, $this>
     */
    public function principals(): HasMany
    {
        return $this->hasMany(Principal::class);
    }

    /**
     * @return HasMany<ProviderKey, $this>
     */
    public function providerKeys(): HasMany
    {
        return $this->hasMany(ProviderKey::class);
    }

    public function principalForUser(User $user): Principal
    {
        return $this->principals()->firstOrCreate([
            'type' => Principal::TypeUser,
            'user_id' => $user->id,
        ]);
    }

    public function principalForAgent(Agent $agent): Principal
    {
        if ($agent->workspace_id !== $this->id) {
            throw new LogicException('Agent does not belong to this workspace.');
        }

        return $this->principals()->firstOrCreate([
            'type' => Principal::TypeAgent,
            'agent_id' => $agent->id,
        ]);
    }

    /**
     * @return Collection<int, Principal>
     */
    public function availablePrincipalsForTeam(Team $team): Collection
    {
        $users = $team->members()
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->principalForUser($user)->load('user'));

        $agents = $this->agents()
            ->orderBy('name')
            ->get()
            ->map(fn (Agent $agent) => $this->principalForAgent($agent)->load('agent'));

        return $users->merge($agents)->values();
    }

    /**
     * Get the team this workspace belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function filesystemRoot(): string
    {
        return storage_path("app/workspaces/{$this->id}");
    }

    public function filesystem(): WorkspaceFilesystemService
    {
        return new WorkspaceFilesystemService($this);
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
