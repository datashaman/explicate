<?php

namespace App\Models;

use Database\Factories\PrincipalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workspace_id', 'type', 'user_id', 'agent_id'])]
class Principal extends Model
{
    public const string TypeUser = 'user';

    public const string TypeAgent = 'agent';

    /** @use HasFactory<PrincipalFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function sentPosts(): HasMany
    {
        return $this->hasMany(Post::class, 'sender_principal_id');
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function receivedPosts(): HasMany
    {
        return $this->hasMany(Post::class, 'recipient_principal_id');
    }

    public function label(): string
    {
        return $this->user?->name ?? $this->agent?->name ?? __('Unknown principal');
    }
}
