<?php

namespace App\Actions\Agents;

use App\Ai\Agents\TopicForgeMentionAgent;
use App\Enums\AgentTaskStatus;
use App\Models\AgentTask;
use App\Models\Post;
use App\Models\User;
use Laravel\Ai\Enums\Lab;
use RuntimeException;
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
            $version = $task->agent->latestVersion;

            if (! $version) {
                throw new RuntimeException('Agent does not have a version to execute.');
            }

            $response = TopicForgeMentionAgent::make(
                task: $task,
                toolUser: $this->toolUserFor($task),
            )->prompt(
                $this->promptFor($task->post),
                provider: Lab::from($version->provider->value),
                model: $version->model,
            );

            $task->forceFill([
                'status' => AgentTaskStatus::Completed,
                'locked_at' => null,
                'last_error' => null,
            ])->save();

            return $task->syncStatusPost($response->text);
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

    protected function promptFor(Post $post): string
    {
        return trim($post->body);
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
