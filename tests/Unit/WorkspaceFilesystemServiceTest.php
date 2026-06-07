<?php

use App\Models\Workspace;
use App\Services\WorkspaceFilesystemService;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

function makeService(): WorkspaceFilesystemService
{
    $workspace = new Workspace;
    $workspace->id = 1;

    $tmpRoot = sys_get_temp_dir().'/tf-fs-test-'.Str::uuid();
    mkdir($tmpRoot, 0755, true);

    return new WorkspaceFilesystemService($workspace, $tmpRoot);
}

afterEach(function () {
    if (isset($this->service)) {
        $root = $this->service->root();
        if (is_dir($root)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getRealPath()) : unlink($entry->getRealPath());
            }
            rmdir($root);
        }
    }
});

test('root returns the workspace filesystem root', function () {
    $workspace = new Workspace;
    $workspace->id = 42;
    $service = new WorkspaceFilesystemService($workspace);

    expect($service->root())->toBe(storage_path('app/workspaces/42'));
});

test('list returns empty array for an empty directory', function () {
    $this->service = makeService();

    expect($this->service->list())->toBe([]);
});

test('list returns folders before files, both alphabetical', function () {
    $this->service = makeService();
    $root = $this->service->root();

    file_put_contents("{$root}/zebra.txt", '');
    file_put_contents("{$root}/alpha.txt", '');
    mkdir("{$root}/mango", 0755, true);
    mkdir("{$root}/banana", 0755, true);

    $entries = $this->service->list();

    expect($entries)->toHaveCount(4);
    expect($entries[0])->toMatchArray(['name' => 'banana', 'type' => 'folder']);
    expect($entries[1])->toMatchArray(['name' => 'mango', 'type' => 'folder']);
    expect($entries[2])->toMatchArray(['name' => 'alpha.txt', 'type' => 'file']);
    expect($entries[3])->toMatchArray(['name' => 'zebra.txt', 'type' => 'file']);
});

test('list returns entries in a subdirectory', function () {
    $this->service = makeService();
    $root = $this->service->root();

    mkdir("{$root}/docs", 0755, true);
    file_put_contents("{$root}/docs/readme.md", '');

    $entries = $this->service->list('docs');

    expect($entries)->toHaveCount(1);
    expect($entries[0])->toMatchArray(['name' => 'readme.md', 'path' => 'docs/readme.md', 'type' => 'file']);
});

test('write creates a file with content', function () {
    $this->service = makeService();

    $this->service->write('hello.txt', 'world');

    expect(file_get_contents($this->service->root().'/hello.txt'))->toBe('world');
});

test('write creates parent directories automatically', function () {
    $this->service = makeService();

    $this->service->write('a/b/c/deep.txt', 'nested');

    expect(file_exists($this->service->root().'/a/b/c/deep.txt'))->toBeTrue();
    expect(file_get_contents($this->service->root().'/a/b/c/deep.txt'))->toBe('nested');
});

test('read returns file content', function () {
    $this->service = makeService();

    $this->service->write('note.txt', 'hello');

    expect($this->service->read('note.txt'))->toBe('hello');
});

test('read throws when file does not exist', function () {
    $this->service = makeService();

    expect(fn () => $this->service->read('missing.txt'))->toThrow(RuntimeException::class);
});

test('mkdir creates a directory', function () {
    $this->service = makeService();

    $this->service->mkdir('reports');

    expect(is_dir($this->service->root().'/reports'))->toBeTrue();
});

test('delete removes a file', function () {
    $this->service = makeService();

    $this->service->write('temp.txt', '');
    $this->service->delete('temp.txt');

    expect(file_exists($this->service->root().'/temp.txt'))->toBeFalse();
});

test('delete removes a directory and its contents', function () {
    $this->service = makeService();

    $this->service->write('folder/nested.txt', 'data');
    $this->service->delete('folder');

    expect(is_dir($this->service->root().'/folder'))->toBeFalse();
});

test('exists returns true for a file', function () {
    $this->service = makeService();

    $this->service->write('present.txt', '');

    expect($this->service->exists('present.txt'))->toBeTrue();
    expect($this->service->exists('absent.txt'))->toBeFalse();
});

test('path traversal via .. is rejected', function () {
    $this->service = makeService();

    expect(fn () => $this->service->read('../etc/passwd'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->service->write('../../escape.txt', ''))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->service->delete('../other'))->toThrow(InvalidArgumentException::class);
});

test('backslash path traversal is rejected', function () {
    $this->service = makeService();

    expect(fn () => $this->service->read('..\\etc\\passwd'))->toThrow(InvalidArgumentException::class);
});

test('workspace filesystem helper returns a service scoped to the workspace root', function () {
    $workspace = new Workspace;
    $workspace->id = 99;
    $service = $workspace->filesystem();

    expect($service)->toBeInstanceOf(WorkspaceFilesystemService::class);
    expect($service->root())->toBe(storage_path('app/workspaces/99'));
});
