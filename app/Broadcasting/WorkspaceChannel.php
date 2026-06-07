<?php

namespace App\Broadcasting;

use App\Models\User;
use App\Models\Workspace;

class WorkspaceChannel
{
    public function __construct() {}

    /**
     * Authenticate the user's access to the channel.
     */
    public function join(User $user, int $workspaceId): bool
    {
        $workspace = Workspace::query()->find($workspaceId);

        return $workspace !== null && $user->belongsToTeam($workspace->team);
    }
}
