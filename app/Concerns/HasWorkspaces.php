<?php

namespace App\Concerns;

use App\Data\UserWorkspace;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

trait HasWorkspaces
{
    /**
     * Get the user's current workspace.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    /**
     * Switch to the given workspace.
     */
    public function switchWorkspace(Workspace $workspace): bool
    {
        if (! $this->currentTeam || $workspace->team_id !== $this->currentTeam->id) {
            return false;
        }

        $this->update(['current_workspace_id' => $workspace->id]);
        $this->setRelation('currentWorkspace', $workspace);

        return true;
    }

    /**
     * Determine if the given workspace is the user's current workspace.
     */
    public function isCurrentWorkspace(Workspace $workspace): bool
    {
        return $this->current_workspace_id === $workspace->id;
    }

    /**
     * Get the current team's workspaces as a collection of UserWorkspace objects.
     *
     * @return Collection<int, UserWorkspace>
     */
    public function toUserWorkspaces(): Collection
    {
        if (! $this->currentTeam) {
            return collect();
        }

        return $this->currentTeam
            ->workspaces()
            ->orderBy('name')
            ->get()
            ->map(fn (Workspace $workspace) => new UserWorkspace(
                id: $workspace->id,
                name: $workspace->name,
                slug: $workspace->slug,
                isCurrent: $this->isCurrentWorkspace($workspace),
            ));
    }
}
