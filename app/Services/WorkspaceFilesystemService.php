<?php

namespace App\Services;

use App\Models\Workspace;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class WorkspaceFilesystemService
{
    private string $root;

    public function __construct(Workspace $workspace, ?string $root = null)
    {
        $resolved = $root ?? storage_path("app/workspaces/{$workspace->id}");
        // Normalize once so realpath comparisons work even when root is a symlink.
        $this->root = realpath($resolved) ?: $resolved;
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * List direct children of a directory, folders before files, both alphabetical.
     *
     * @return array<int, array{name: string, path: string, type: string}>
     */
    public function list(string $directory = ''): array
    {
        $absoluteDir = $directory === ''
            ? $this->root
            : $this->resolvePath($directory);

        if (! is_dir($absoluteDir)) {
            return [];
        }

        $entries = scandir($absoluteDir);
        $folders = [];
        $files = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $absoluteEntry = $absoluteDir.'/'.$entry;
            $relativePath = $directory === '' ? $entry : "{$directory}/{$entry}";

            if (is_dir($absoluteEntry)) {
                $folders[] = ['name' => $entry, 'path' => $relativePath, 'type' => 'folder'];
            } else {
                $files[] = ['name' => $entry, 'path' => $relativePath, 'type' => 'file'];
            }
        }

        usort($folders, fn ($a, $b) => strcmp($a['name'], $b['name']));
        usort($files, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return [...$folders, ...$files];
    }

    public function path(string $path): string
    {
        return $this->resolvePath($path);
    }

    public function read(string $path): string
    {
        $absolute = $this->resolvePath($path);

        if (! file_exists($absolute)) {
            throw new RuntimeException("File not found: {$path}");
        }

        if (is_dir($absolute)) {
            throw new RuntimeException("Path is a directory: {$path}");
        }

        return file_get_contents($absolute);
    }

    public function write(string $path, string $content): void
    {
        $absolute = $this->resolvePath($path);
        $dir = dirname($absolute);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($absolute, $content);
    }

    public function mkdir(string $path): void
    {
        $absolute = $this->resolvePath($path);

        if (! is_dir($absolute)) {
            mkdir($absolute, 0755, true);
        }
    }

    public function delete(string $path): void
    {
        $absolute = $this->resolvePath($path);

        if (is_dir($absolute)) {
            $this->deleteDirectory($absolute);
        } elseif (file_exists($absolute)) {
            unlink($absolute);
        }
    }

    public function exists(string $path): bool
    {
        try {
            return file_exists($this->resolvePath($path));
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function isDirectory(string $path): bool
    {
        try {
            return is_dir($this->resolvePath($path));
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public static function normalizeName(string $name): string
    {
        return trim(preg_replace('/[\/\\\\]+/', '-', $name));
    }

    /**
     * Normalize and resolve a workspace-relative path to an absolute path,
     * rejecting traversal attempts.
     *
     * @throws InvalidArgumentException
     */
    private function resolvePath(string $relativePath): string
    {
        $normalized = str_replace('\\', '/', $relativePath);
        $segments = array_values(array_filter(explode('/', $normalized), fn ($s) => $s !== ''));

        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '.') {
                throw new InvalidArgumentException("Invalid path segment: {$segment}");
            }
        }

        if (empty($segments)) {
            throw new InvalidArgumentException('Path must not be empty.');
        }

        $absolute = $this->root.'/'.implode('/', $segments);

        // Secondary guard: if the path already exists, realpath must stay within root.
        $real = realpath($absolute);
        if ($real !== false && ! str_starts_with($real.DIRECTORY_SEPARATOR, $this->root.DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('Path escapes workspace root.');
        }

        return $absolute;
    }

    private function deleteDirectory(string $absolute): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getRealPath());
            } else {
                unlink($entry->getRealPath());
            }
        }

        rmdir($absolute);
    }
}
