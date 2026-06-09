<?php

namespace App\Actions\Agents;

use App\Ai\Agents\ExplicateMentionAgent;
use App\Enums\AgentTaskStatus;
use App\Models\AgentTask;
use App\Models\Post;
use App\Models\ProviderKey;
use App\Models\User;
use App\Services\GitRepositoryService;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;
use RuntimeException;
use Stringable;
use Throwable;

class ExecuteAgentTask
{
    public function handle(AgentTask $task): ?Post
    {
        $task->loadMissing([
            'agent.latestVersion',
            'agent.workspace.team',
            'post.sender.user',
            'post.topic',
        ]);

        if ($task->status !== AgentTaskStatus::Pending || ! $task->available_at || $task->available_at->isFuture()) {
            return null;
        }

        $task->forceFill([
            'status' => AgentTaskStatus::Processing,
            'locked_at' => now(),
            'attempts' => $task->attempts + 1,
            'last_error' => null,
        ])->save();
        $task->syncStatusPost();

        try {
            $this->syncRepositories($task);

            $version = $task->agent->latestVersion;

            if (! $version) {
                throw new RuntimeException('Agent does not have a version to execute.');
            }

            $providerName = $version->provider->value;
            $this->injectProviderKey($task, $providerName);

            $response = ExplicateMentionAgent::make(
                task: $task,
                toolUser: $this->toolUserFor($task),
            )->prompt(
                $this->promptFor($task->post),
                provider: Lab::from($providerName),
                model: $version->model,
            );

            $this->restoreProviderKey($providerName);

            $task->forceFill([
                'status' => AgentTaskStatus::Completed,
                'locked_at' => null,
                'last_error' => null,
            ])->save();

            return $task->syncStatusPost($this->cleanReplyText($task, $response->text));
        } catch (Throwable $throwable) {
            $task->forceFill([
                'status' => AgentTaskStatus::Failed,
                'locked_at' => null,
                'last_error' => $throwable->getMessage(),
            ])->save();
            $task->syncStatusPost();

            throw $throwable;
        }
    }

    private function syncRepositories(AgentTask $task): void
    {
        $workspace = $task->agent->workspace;

        foreach ($workspace->repositories as $repo) {
            try {
                (new GitRepositoryService($repo))->sync();
            } catch (Throwable $e) {
                Log::warning("Failed to sync repository [{$repo->name}] for workspace [{$workspace->id}]: {$e->getMessage()}");
            }
        }
    }

    protected function promptFor(Post $post): string
    {
        return trim($post->body);
    }

    private function cleanReplyText(AgentTask $task, string|Stringable $text): string
    {
        $agent = $task->agent;
        $reply = trim((string) $text);
        $name = preg_quote($agent->name, '/');
        $slug = preg_quote($agent->slug, '/');

        return trim((string) preg_replace(
            "/^{$name}(?:\\s+\\(@{$slug}\\)|\\s+@{$slug})?\\s*:\\s*/i",
            '',
            $reply,
            1,
        ));
    }

    private function injectProviderKey(AgentTask $task, string $providerName): void
    {
        $workspace = $task->agent->workspace;
        $team = $workspace->team;

        $key = ProviderKey::query()
            ->where('provider', $providerName)
            ->where(fn ($q) => $q
                ->where('workspace_id', $workspace->id)
                ->orWhere(fn ($q2) => $q2
                    ->where('team_id', $team->id)
                    ->whereNull('workspace_id')
                )
            )
            ->orderByRaw('workspace_id IS NULL')
            ->value('api_key');

        if ($key) {
            config(["ai.providers.{$providerName}.key" => $key]);
            Ai::forgetInstance($providerName);
        }
    }

    private function restoreProviderKey(string $providerName): void
    {
        config(["ai.providers.{$providerName}.key" => env(strtoupper($providerName).'_API_KEY')]);
        Ai::forgetInstance($providerName);
    }

    private function toolUserFor(AgentTask $task): User
    {
        if ($task->post->sender?->user instanceof User) {
            return $task->post->sender->user;
        }

        $owner = $task->agent->workspace->team->owner();

        if ($owner instanceof User) {
            return $owner;
        }

        throw new RuntimeException('Agent task does not have a user context for tools.');
    }
}
