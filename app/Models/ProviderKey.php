<?php

namespace App\Models;

use App\Enums\Provider;
use Database\Factories\ProviderKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['team_id', 'workspace_id', 'provider', 'api_key'])]
class ProviderKey extends Model
{
    /** @use HasFactory<ProviderKeyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'provider' => Provider::class,
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
