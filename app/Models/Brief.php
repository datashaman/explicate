<?php

namespace App\Models;

use App\Enums\BriefCategory;
use Database\Factories\BriefFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'source_thread_id',
    'category',
    'summary',
    'current_behaviour',
    'expected_behaviour',
    'acceptance_criteria',
    'out_of_scope',
])]
class Brief extends Model
{
    /** @use HasFactory<BriefFactory> */
    use HasFactory, SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'acceptance_criteria' => '[]',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => BriefCategory::class,
            'acceptance_criteria' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function sourceThread(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'source_thread_id');
    }

    /**
     * @return HasOne<Plan, $this>
     */
    public function plan(): HasOne
    {
        return $this->hasOne(Plan::class);
    }
}
