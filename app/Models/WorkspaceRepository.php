<?php

namespace App\Models;

use Database\Factories\WorkspaceRepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'name', 'url', 'branch', 'auth_type', 'ssh_private_key', 'access_token'])]
class WorkspaceRepository extends Model
{
    /** @use HasFactory<WorkspaceRepositoryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'ssh_private_key' => 'encrypted',
            'access_token' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function localPath(): string
    {
        return storage_path("app/workspace-repos/{$this->workspace_id}/{$this->id}");
    }

    public function isCloned(): bool
    {
        return is_dir($this->localPath().'/.git');
    }
}
