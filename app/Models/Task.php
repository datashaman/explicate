<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['plan_id', 'text', 'expected_artifact', 'status', 'position'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => TaskStatus::Pending->value,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
