<?php

namespace App\Models;

use App\Actions\Agents\SyncAgentChatReplies;
use App\Enums\PostListColumn;
use App\Enums\PostStatus;
use App\Events\WorkspacePostsChanged;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Fillable(['thread_id', 'sender_principal_id', 'ulid', 'body', 'status', 'deleted_by_user_id'])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $touches = ['thread'];

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
        });

        static::created(function (Post $post) {
            $post->syncMentionedAgentTasks();
            $post->broadcastWorkspaceChange();
        });

        static::updated(function (Post $post) {
            if ($post->wasChanged('status') || $post->wasChanged('body')) {
                $post->syncMentionedAgentTasks();
            }

            if ($post->wasChanged(['status', 'body', 'thread_id', 'sender_principal_id', 'deleted_by_user_id'])) {
                $post->broadcastWorkspaceChange();
            }
        });

        static::deleted(function (Post $post) {
            $post->broadcastWorkspaceChange();
        });

        static::forceDeleted(function (Post $post) {
            $post->loadMissing('thread');

            if ($post->thread && ! $post->thread->posts()->withTrashed()->exists()) {
                $post->thread->forceDelete();
            }
        });

        static::restored(function (Post $post) {
            $post->broadcastWorkspaceChange();
        });
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

    public function syncMentionedAgentTasks(): void
    {
        app(SyncAgentChatReplies::class)->handle($this);
    }

    public function broadcastWorkspaceChange(): void
    {
        $this->loadMissing('thread');

        if ($this->thread?->workspace_id) {
            WorkspacePostsChanged::dispatch($this->thread->workspace_id, $this->id);
        }
    }

    public function moveToDraft(): void
    {
        $this->update(['status' => PostStatus::Draft]);
    }

    /**
     * @return Collection<int, Agent>
     */
    public function mentionedAgents(): Collection
    {
        $slugs = $this->mentionedAgentSlugs();

        if ($slugs->isEmpty()) {
            return Collection::make();
        }

        $this->loadMissing('thread.workspace');

        return $this->thread->workspace
            ->agents()
            ->whereIn('slug', $slugs->all())
            ->get();
    }

    /**
     * @return Collection<int, string>
     */
    public function mentionedAgentSlugs(): Collection
    {
        preg_match_all('/(?<![\w@])@([a-z0-9][a-z0-9-]*)\b/i', $this->body ?? '', $matches);

        return Collection::make($matches[1] ?? [])
            ->map(fn (string $slug): string => Str::lower($slug))
            ->unique()
            ->values();
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
     * @return BelongsTo<User, $this>
     */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
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
     * @return array{post: string, sender: string, sent?: string, saved?: string, attachments: string, status: string}
     */
    public function listSortValues(?string $dateKey = null): array
    {
        $attachmentsCount = (int) ($this->attachments_count ?? $this->attachments()->count());
        $dateKey ??= $this->dateListColumn()->value;

        $values = [
            PostListColumn::Post->value => Str::lower($this->preview()),
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

    public function preview(): string
    {
        return Str::of($this->body ?? '')
            ->squish()
            ->limit(80, '...')
            ->whenEmpty(fn () => __('No content.'))
            ->toString();
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}
