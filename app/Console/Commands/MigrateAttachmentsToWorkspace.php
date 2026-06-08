<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateAttachmentsToWorkspace extends Command
{
    protected $signature = 'attachments:migrate-to-workspace {--dry-run : Preview without making changes}';

    protected $description = 'Move existing attachments from the public disk to their workspace filesystem';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $moved = 0;
        $missing = 0;
        $failed = 0;

        Attachment::withTrashed()
            ->with('post.topic.workspace')
            ->lazyById()
            ->each(function (Attachment $attachment) use ($dryRun, &$moved, &$missing, &$failed): void {
                $workspace = $attachment->post?->topic?->workspace;

                if (! $workspace) {
                    $this->warn("Attachment {$attachment->id}: no workspace found, skipping.");
                    $failed++;

                    return;
                }

                if (! Storage::disk('public')->exists($attachment->path)) {
                    $this->line("Attachment {$attachment->id}: not on public disk, skipping.");
                    $missing++;

                    return;
                }

                if ($workspace->filesystem()->exists($attachment->path)) {
                    $this->line("Attachment {$attachment->id}: already in workspace filesystem, skipping.");
                    $moved++;

                    return;
                }

                $this->line("Attachment {$attachment->id}: {$attachment->path} → workspace {$workspace->id}");

                if (! $dryRun) {
                    $content = Storage::disk('public')->get($attachment->path);
                    $workspace->filesystem()->write($attachment->path, $content);
                    Storage::disk('public')->delete($attachment->path);
                }

                $moved++;
            });

        $this->info("Done. Moved: {$moved}, already migrated/missing: {$missing}, failed: {$failed}.");

        return self::SUCCESS;
    }
}
