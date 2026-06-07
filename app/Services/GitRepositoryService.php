<?php

namespace App\Services;

use App\Models\WorkspaceRepository;
use RuntimeException;

class GitRepositoryService
{
    public function __construct(private WorkspaceRepository $repository) {}

    public function sync(): void
    {
        $path = $this->repository->localPath();

        if ($this->repository->isCloned()) {
            $this->pull($path);
        } else {
            $this->clone($path);
        }
    }

    public function validate(): void
    {
        $this->runGit(
            ['git', 'ls-remote', '--heads', $this->repository->url],
            workingDir: null,
        );
    }

    public function remove(): void
    {
        $path = $this->repository->localPath();

        if (is_dir($path)) {
            $this->deleteDirectory($path);
        }
    }

    /**
     * @param  array<string>  $command
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    public function run(array $command): array
    {
        return $this->runGit($command, workingDir: $this->repository->localPath());
    }

    private function clone(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $this->runGit(
            ['git', 'clone', '--branch', $this->repository->branch, '--', $this->repository->url, $path],
            workingDir: null,
        );
    }

    private function pull(string $path): void
    {
        $this->runGit(['git', 'pull', '--ff-only'], workingDir: $path);
    }

    /**
     * @param  array<string>  $command
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    private function runGit(array $command, ?string $workingDir): array
    {
        $env = $this->buildEnv();
        $keyFile = $env['_ssh_key_file'] ?? null;
        unset($env['_ssh_key_file']);

        try {
            $descriptor = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open($command, $descriptor, $pipes, $workingDir, array_merge(getenv() ?: [], $env));

            if (! is_resource($process)) {
                throw new RuntimeException('Failed to start git process.');
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);

            if ($exitCode !== 0) {
                throw new RuntimeException("Git command failed (exit {$exitCode}): ".trim($stderr ?: $stdout));
            }

            return ['stdout' => $stdout ?: '', 'stderr' => $stderr ?: '', 'exit_code' => $exitCode];
        } finally {
            if ($keyFile && file_exists($keyFile)) {
                unlink($keyFile);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildEnv(): array
    {
        if ($this->repository->auth_type === 'ssh') {
            return $this->buildSshEnv();
        }

        return $this->buildTokenEnv();
    }

    /**
     * @return array<string, string>
     */
    private function buildSshEnv(): array
    {
        $keyFile = tempnam(sys_get_temp_dir(), 'git_key_');
        file_put_contents($keyFile, $this->repository->ssh_private_key."\n");
        chmod($keyFile, 0600);

        return [
            'GIT_SSH_COMMAND' => "ssh -i {$keyFile} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null",
            '_ssh_key_file' => $keyFile,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildTokenEnv(): array
    {
        return [
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'http.extraHeader',
            'GIT_CONFIG_VALUE_0' => 'Authorization: Bearer '.$this->repository->access_token,
        ];
    }

    private function deleteDirectory(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getRealPath()) : unlink($entry->getRealPath());
        }

        rmdir($path);
    }
}
