<?php

namespace App\Models;

use App\Enums\Provider;
use App\Enums\ReasoningEffort;
use Database\Factories\AgentVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['agent_id', 'version', 'provider', 'model', 'reasoning_effort', 'prompt'])]
class AgentVersion extends Model
{
    /** @use HasFactory<AgentVersionFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $casts = [
        'provider' => Provider::class,
        'reasoning_effort' => ReasoningEffort::class,
        'created_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (AgentVersion $agentVersion) {
            $agentVersion->created_at = now();

            if (empty($agentVersion->version)) {
                $agentVersion->version = ($agentVersion->agent->versions()->max('version') ?? 0) + 1;
            }
        });
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
