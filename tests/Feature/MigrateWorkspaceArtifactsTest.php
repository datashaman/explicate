<?php

use App\Models\WorkspaceFile;
use Illuminate\Support\Facades\Artisan;

afterEach(function () {
    $workspacesDir = storage_path('app/workspaces');
    if (is_dir($workspacesDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($workspacesDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getRealPath()) : unlink($entry->getRealPath());
        }
        rmdir($workspacesDir);
    }
});

test('migration command writes workspace files and folders to disk', function () {
    [$user, $workspace] = userWithWorkspace();

    $folder = WorkspaceFile::factory()->for($workspace)->folder()->create(['name' => 'docs', 'path' => 'docs']);
    WorkspaceFile::factory()->for($workspace)->create([
        'name' => 'readme.md',
        'path' => 'docs/readme.md',
        'parent_id' => $folder->id,
        'content' => '# Readme',
    ]);
    WorkspaceFile::factory()->for($workspace)->create([
        'name' => 'notes.md',
        'path' => 'notes.md',
        'content' => 'My notes',
    ]);

    $exitCode = Artisan::call('app:migrate-workspace-artifacts');

    expect($exitCode)->toBe(0);
    expect($workspace->filesystem()->isDirectory('docs'))->toBeTrue();
    expect($workspace->filesystem()->exists('docs/readme.md'))->toBeTrue();
    expect($workspace->filesystem()->read('docs/readme.md'))->toBe('# Readme');
    expect($workspace->filesystem()->exists('notes.md'))->toBeTrue();
    expect($workspace->filesystem()->read('notes.md'))->toBe('My notes');
});

test('migration skips files that already exist on disk by default', function () {
    [$user, $workspace] = userWithWorkspace();

    WorkspaceFile::factory()->for($workspace)->create([
        'name' => 'existing.md',
        'path' => 'existing.md',
        'content' => 'DB content',
    ]);
    $workspace->filesystem()->write('existing.md', 'Disk content');

    Artisan::call('app:migrate-workspace-artifacts');

    expect($workspace->filesystem()->read('existing.md'))->toBe('Disk content');
});

test('migration overwrites existing files when --force is passed', function () {
    [$user, $workspace] = userWithWorkspace();

    WorkspaceFile::factory()->for($workspace)->create([
        'name' => 'existing.md',
        'path' => 'existing.md',
        'content' => 'DB content',
    ]);
    $workspace->filesystem()->write('existing.md', 'Disk content');

    Artisan::call('app:migrate-workspace-artifacts', ['--force' => true]);

    expect($workspace->filesystem()->read('existing.md'))->toBe('DB content');
});

test('migration command is idempotent when run twice without --force', function () {
    [$user, $workspace] = userWithWorkspace();

    WorkspaceFile::factory()->for($workspace)->create([
        'name' => 'spec.md',
        'path' => 'spec.md',
        'content' => 'Original',
    ]);

    Artisan::call('app:migrate-workspace-artifacts');
    $workspace->filesystem()->write('spec.md', 'Edited on disk');
    Artisan::call('app:migrate-workspace-artifacts');

    expect($workspace->filesystem()->read('spec.md'))->toBe('Edited on disk');
});
