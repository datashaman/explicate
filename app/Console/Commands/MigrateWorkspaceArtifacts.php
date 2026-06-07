<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('app:migrate-workspace-artifacts {--force : Overwrite existing disk files with DB content}')]
#[Description('Copy existing workspace_files DB records to the real filesystem.')]
class MigrateWorkspaceArtifacts extends Command
{
    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $workspaces = Workspace::withTrashed()->get();

        if ($workspaces->isEmpty()) {
            $this->info('No workspaces found.');

            return self::SUCCESS;
        }

        $created = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($workspaces as $workspace) {
            $this->line("Workspace [{$workspace->id}] {$workspace->name}");

            $fs = $workspace->filesystem();

            // Load all files ordered by path length so parents are written before children.
            $rows = DB::table('workspace_files')
                ->where('workspace_id', $workspace->id)
                ->orderByRaw('LENGTH(path)')
                ->orderBy('path')
                ->get();

            if ($rows->isEmpty()) {
                $this->line('  No files.');

                continue;
            }

            foreach ($rows as $row) {
                try {
                    if ($row->type === 'folder') {
                        if ($fs->exists($row->path) && $fs->isDirectory($row->path)) {
                            $this->line("  skip  {$row->path}/");
                            $skipped++;
                        } else {
                            $fs->mkdir($row->path);
                            $this->line("  mkdir {$row->path}/");
                            $created++;
                        }
                    } else {
                        if (! $force && $fs->exists($row->path) && ! $fs->isDirectory($row->path)) {
                            $this->line("  skip  {$row->path}");
                            $skipped++;
                        } else {
                            $fs->write($row->path, $row->content ?? '');
                            $this->line("  write {$row->path}");
                            $created++;
                        }
                    }
                } catch (\Throwable $e) {
                    $this->warn("  error {$row->path}: {$e->getMessage()}");
                    $errors++;
                }
            }
        }

        $this->newLine();
        $this->line("Done. Created: {$created}  Skipped: {$skipped}  Errors: {$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
