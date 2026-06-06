<?php

namespace App\Actions\Agents;

use App\Enums\AgentTaskStatus;
use App\Enums\MessageStatus;
use App\Models\AgentTask;
use App\Models\Message;
use Laravel\Ai\Enums\Lab;
use RuntimeException;
use Throwable;

use function Laravel\Ai\agent as laravelAiAgent;

class ExecuteAgentTask
{
    public function handle(AgentTask $task): ?Message
    {
        $task->loadMissing([
            'agent.latestVersion',
            'agent.workspace',
            'message.sender',
            'message.topic',
        ]);

        if ($task->status !== AgentTaskStatus::Pending) {
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
                    $this->promptFor($task->message),
                    provider: Lab::from($version->provider->value),
                    model: $version->model,
                );

            $reply = $task->message->topic->messages()->create([
                'sender_principal_id' => $task->agent->workspace->principalForAgent($task->agent)->id,
                'recipient_principal_id' => $task->message->sender_principal_id,
                'title' => __('Re: :title', ['title' => $task->message->title]),
                'body' => $response->text,
                'status' => MessageStatus::Published,
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

    protected function promptFor(Message $message): string
    {
        return trim(implode("\n\n", array_filter([
            '# '.$message->title,
            $message->body,
        ])));
    }
}
