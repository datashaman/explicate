<?php

namespace App\Models;

use App\Enums\WorkspaceFileType;
use Database\Factories\WorkspaceFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['workspace_id', 'parent_id', 'type', 'name', 'path', 'content'])]
class WorkspaceFile extends Model
{
    /** @use HasFactory<WorkspaceFileFactory> */
    use HasFactory;

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (WorkspaceFile $file): void {
            $file->name = static::normalizeName($file->name);
            $file->path = static::buildPath($file->parent, $file->name);

            if ($file->type === WorkspaceFileType::Folder) {
                $file->content = null;
            }
        });

        static::saved(function (WorkspaceFile $file): void {
            if ($file->wasChanged(['name', 'path', 'parent_id'])) {
                $file->refreshChildPaths();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WorkspaceFileType::class,
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
     * @return BelongsTo<WorkspaceFile, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(WorkspaceFile::class, 'parent_id');
    }

    /**
     * @return HasMany<WorkspaceFile, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(WorkspaceFile::class, 'parent_id')
            ->orderByRaw("case when type = 'folder' then 0 else 1 end")
            ->orderBy('name');
    }

    public function isFolder(): bool
    {
        return $this->type === WorkspaceFileType::Folder;
    }

    public function isFile(): bool
    {
        return $this->type === WorkspaceFileType::File;
    }

    public static function normalizeName(string $name): string
    {
        return Str::of($name)->trim()->replaceMatches('/[\/\\\\]+/', '-')->value();
    }

    public static function normalizePath(string $path): string
    {
        return Str::of($path)
            ->trim()
            ->replaceMatches('/[\\\\]+/', '/')
            ->replaceMatches('/\/+/', '/')
            ->trim('/')
            ->explode('/')
            ->filter()
            ->map(fn (string $segment): string => static::normalizeName($segment))
            ->implode('/');
    }

    public static function buildPath(?WorkspaceFile $parent, string $name): string
    {
        $name = static::normalizeName($name);

        if (! $parent instanceof WorkspaceFile) {
            return $name;
        }

        return "{$parent->path}/{$name}";
    }

    public function refreshChildPaths(): void
    {
        $this->children()->get()->each(function (WorkspaceFile $child): void {
            $child->path = static::buildPath($this, $child->name);
            $child->save();
        });
    }
}
