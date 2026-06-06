<?php

namespace App\Models;

use App\Enums\AgentTaskStatus;
use App\Enums\MessageStatus;
use App\Events\MessageSent;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Fillable(['topic_id', 'sender_principal_id', 'recipient_principal_id', 'title', 'slug', 'ulid', 'body', 'status'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => MessageStatus::class,
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Message $message) {
            if (empty($message->ulid)) {
                $message->ulid = (string) Str::ulid();
            }

            if (empty($message->slug)) {
                $message->slug = static::generateUniqueSlug($message->topic_id, $message->title);
            }
        });

        static::updating(function (Message $message) {
            if ($message->isDirty('title')) {
                $message->slug = static::generateUniqueSlug($message->topic_id, $message->title, $message->id);
            }
        });

        static::created(function (Message $message) {
            if ($message->status === MessageStatus::Published) {
                MessageSent::dispatch($message);
            }
        });

        static::updated(function (Message $message) {
            if ($message->wasChanged('status') && $message->status === MessageStatus::Published) {
                $message->makeAssignedAgentTasksAvailable();
                MessageSent::dispatch($message);
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
            ->wherePivot('event_type', AgentTask::EventMessageAssigned)
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
            ->where('event_type', AgentTask::EventMessageAssigned);

        if ($validAgentIds->isNotEmpty()) {
            $tasksToRemove->whereNotIn('agent_id', $validAgentIds);
        }

        $tasksToRemove->delete();

        $validAgentIds->each(function (int $agentId): void {
            $this->agentTasks()->firstOrCreate([
                'agent_id' => $agentId,
                'event_type' => AgentTask::EventMessageAssigned,
            ], [
                'status' => AgentTaskStatus::Pending,
                'available_at' => $this->status === MessageStatus::Published ? now() : null,
            ]);
        });
    }

    public function makeAssignedAgentTasksAvailable(): void
    {
        $this->agentTasks()
            ->where('event_type', AgentTask::EventMessageAssigned)
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
     * @return BelongsTo<Principal, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Principal::class, 'recipient_principal_id');
    }

    /**
     * @return BelongsTo<Principal, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(Principal::class, 'sender_principal_id');
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public function listMeta(bool $showSender, bool $showRecipient, ?string $recipientFallback = null): array
    {
        $meta = [];

        if ($showSender && $this->sender) {
            $meta[] = ['label' => __('From'), 'value' => $this->sender->label()];
        }

        if ($showRecipient) {
            $recipient = $this->recipient?->label() ?? $recipientFallback;

            if ($recipient) {
                $meta[] = ['label' => __('To'), 'value' => $recipient];
            }
        }

        $meta[] = [
            'label' => $this->status === MessageStatus::Draft ? __('Saved') : __('Sent'),
            'value' => $this->updated_at->diffForHumans(),
        ];

        return $meta;
    }

    /**
     * @return array{name: string, from: string, to: string, sent?: string, saved?: string, attachments: string, status: string}
     */
    public function listSortValues(?string $recipientFallback = null, ?string $dateKey = null): array
    {
        $attachmentsCount = (int) ($this->attachments_count ?? $this->attachments()->count());
        $dateKey ??= $this->status === MessageStatus::Draft ? 'saved' : 'sent';

        $values = [
            'name' => Str::lower($this->title),
            'from' => Str::lower($this->sender?->label() ?? ''),
            'to' => Str::lower($this->recipient?->label() ?? $recipientFallback ?? ''),
            'attachments' => str_pad((string) $attachmentsCount, 10, '0', STR_PAD_LEFT),
            'status' => Str::lower($this->status->label()),
        ];

        $values[$dateKey] = str_pad((string) $this->updated_at->timestamp, 20, '0', STR_PAD_LEFT);

        return $values;
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}
