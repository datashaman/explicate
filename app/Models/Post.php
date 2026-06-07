<?php

namespace App\Models;

use App\Enums\AgentTaskStatus;
use App\Enums\PostListColumn;
use App\Enums\PostStatus;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Fillable(['topic_id', 'thread_id', 'sender_principal_id', 'title', 'slug', 'ulid', 'body', 'status'])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Post $post) {
            if (empty($post->ulid)) {
                $post->ulid = (string) Str::ulid();
            }

            if (empty($post->slug)) {
                $post->slug = static::generateUniqueSlug($post->topic_id, $post->title);
            }
        });

        static::updating(function (Post $post) {
            if ($post->isDirty('title')) {
                $post->slug = static::generateUniqueSlug($post->topic_id, $post->title, $post->id);
            }
        });

        static::updated(function (Post $post) {
            if ($post->wasChanged('status') && $post->status === PostStatus::Published) {
                $post->makeAssignedAgentTasksAvailable();
            }
        });
    }

    protected static function generateUniqueSlug(int $topicId, string $name, ?int $excludeId = null): string
    {
        $base = Str::slug($name);

        $query = static::withTrashed()
            ->where('topic_id', $topicId)
            ->where(function ($q) use ($base) {
                $q->where('slug', $base)->orWhere('slug', 'like', $base.'-%');
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $existing = $query->pluck('slug');

        $max = $existing
            ->map(function (string $slug) use ($base): ?int {
                if ($slug === $base) {
                    return 0;
                } elseif (preg_match('/^'.preg_quote($base, '/').'-(\d+)$/', $slug, $m)) {
                    return (int) $m[1];
                }

                return null;
            })
            ->filter(fn (?int $n) => $n !== null)
            ->max() ?? 0;

        return $existing->isEmpty() ? $base : $base.'-'.($max + 1);
    }

    /**
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class)->orderBy('filename');
    }

    /**
     * @return HasMany<AgentTask, $this>
     */
    public function agentTasks(): HasMany
    {
        return $this->hasMany(AgentTask::class);
    }

    /**
     * @return BelongsToMany<Agent, $this>
     */
    public function assignedAgents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_tasks')
            ->wherePivot('event_type', AgentTask::EventPostAssigned)
            ->withPivot(['id', 'status', 'available_at', 'locked_at', 'attempts', 'last_error'])
            ->withTimestamps();
    }

    /**
     * @param  iterable<int, int|string|Agent>  $agents
     */
    public function assignAgents(iterable $agents): void
    {
        $agentIds = Collection::make($agents)
            ->map(fn (int|string|Agent $agent): int => $agent instanceof Agent ? $agent->id : (int) $agent)
            ->filter(fn (int $agentId): bool => $agentId > 0)
            ->unique()
            ->values();

        $validAgentIds = $this->topic
            ->agents()
            ->whereKey($agentIds->all())
            ->pluck('id');

        $tasksToRemove = $this->agentTasks()
            ->where('event_type', AgentTask::EventPostAssigned);

        if ($validAgentIds->isNotEmpty()) {
            $tasksToRemove->whereNotIn('agent_id', $validAgentIds);
        }

        $tasksToRemove->delete();

        $validAgentIds->each(function (int $agentId): void {
            $this->agentTasks()->firstOrCreate([
                'agent_id' => $agentId,
                'event_type' => AgentTask::EventPostAssigned,
            ], [
                'status' => AgentTaskStatus::Pending,
                'available_at' => $this->status === PostStatus::Published ? now() : null,
            ]);
        });
    }

    public function makeAssignedAgentTasksAvailable(): void
    {
        $this->agentTasks()
            ->where('event_type', AgentTask::EventPostAssigned)
            ->whereNull('available_at')
            ->update(['available_at' => now()]);
    }

    /**
     * @return BelongsTo<Topic, $this>
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /**
     * @return BelongsTo<Principal, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(Principal::class, 'sender_principal_id');
    }

    /**
     * @return list<array{key: string, label: string, value: string, title?: string}>
     */
    public function listMeta(bool $showSender, ?string $timezone = null): array
    {
        $meta = [];

        if ($showSender && $this->sender) {
            $meta[] = ['key' => PostListColumn::Sender->value, 'label' => __('Sender'), 'value' => $this->sender->label()];
        }

        $meta[] = [
            'key' => $this->dateListColumn()->value,
            'label' => $this->status === PostStatus::Draft ? __('Saved') : __('Sent'),
            'value' => $this->updated_at->diffForHumans(),
            'title' => $this->updated_at->timezone($timezone ?: config('app.timezone'))->isoFormat('LLLL'),
        ];

        return $meta;
    }

    /**
     * @return list<array{key: string, label: string, value: string, title?: string}>
     */
    public function listTopicMeta(bool $showSender, ?string $timezone = null): array
    {
        $meta = [];

        if ($showSender && $this->sender) {
            $meta[] = ['key' => PostListColumn::Sender->value, 'label' => __('Sender'), 'value' => $this->sender->label()];
        }

        $meta[] = ['key' => PostListColumn::Topic->value, 'label' => __('Topic'), 'value' => $this->topic->name];

        $meta[] = [
            'key' => $this->dateListColumn()->value,
            'label' => $this->status === PostStatus::Draft ? __('Saved') : __('Sent'),
            'value' => $this->updated_at->diffForHumans(),
            'title' => $this->updated_at->timezone($timezone ?: config('app.timezone'))->isoFormat('LLLL'),
        ];

        return $meta;
    }

    /**
     * @return array{name: string, sender: string, to: string, sent?: string, saved?: string, attachments: string, status: string}
     */
    public function listSortValues(?string $dateKey = null): array
    {
        $attachmentsCount = (int) ($this->attachments_count ?? $this->attachments()->count());
        $dateKey ??= $this->dateListColumn()->value;

        $values = [
            PostListColumn::Name->value => Str::lower($this->title),
            PostListColumn::Sender->value => Str::lower($this->sender?->label() ?? ''),
            PostListColumn::Attachments->value => str_pad((string) $attachmentsCount, 10, '0', STR_PAD_LEFT),
            'status' => Str::lower($this->status->label()),
        ];

        $values[$dateKey] = str_pad((string) $this->updated_at->timestamp, 20, '0', STR_PAD_LEFT);

        return $values;
    }

    public function dateListColumn(): PostListColumn
    {
        return $this->status === PostStatus::Draft ? PostListColumn::Saved : PostListColumn::Sent;
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}
