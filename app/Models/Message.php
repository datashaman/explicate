<?php

namespace App\Models;

use App\Enums\MessageStatus;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}
