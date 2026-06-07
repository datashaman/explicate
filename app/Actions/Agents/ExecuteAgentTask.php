<?php

namespace App\Actions\Agents;

use App\Enums\AgentTaskStatus;
use App\Enums\PostStatus;
use App\Models\AgentTask;
use App\Models\Post;
use Laravel\Ai\Enums\Lab;
use RuntimeException;
use Throwable;

use function Laravel\Ai\agent as laravelAiAgent;

class ExecuteAgentTask
{
    public function handle(AgentTask $task): ?Post
    {
        $task->loadMissing([
            'agent.latestVersion',
            'agent.workspace',
            'post.sender',
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

        try {
            $version = $task->agent->latestVersion;

            if (! $version) {
                throw new RuntimeException('Agent does not have a version to execute.');
            }

            $response = laravelAiAgent(instructions: $version->prompt)
                ->prompt(
                    $this->promptFor($task->post),
                    provider: Lab::from($version->provider->value),
                    model: $version->model,
                );

            $reply = $task->post->topic->posts()->create([
                'sender_principal_id' => $task->agent->workspace->principalForAgent($task->agent)->id,
                'title' => __('Re: :title', ['title' => $task->post->title]),
                'body' => $response->text,
                'status' => PostStatus::Published,
            ]);

            $task->forceFill([
                'status' => AgentTaskStatus::Completed,
                'locked_at' => null,
                'last_error' => null,
            ])->save();

            return $reply;
        } catch (Throwable $throwable) {
            $task->forceFill([
                'status' => AgentTaskStatus::Failed,
                'locked_at' => null,
                'last_error' => $throwable->getMessage(),
            ])->save();

            throw $throwable;
        }
    }

    protected function promptFor(Post $post): string
    {
        return trim(implode("\n\n", array_filter([
            '# '.$post->title,
            $post->body,
        ])));
    }
}
