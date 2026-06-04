<?php

namespace App\Actions\Workspaces;

use App\Models\Team;
use App\Models\Workspace;

class CreateWorkspace
{
    /**
     * Create a new workspace for the given team.
     */
    public function handle(Team $team, string $name): Workspace
    {
        return $team->workspaces()->create(['name' => $name]);
    }
}
