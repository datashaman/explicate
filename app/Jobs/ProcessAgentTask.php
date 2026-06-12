<?php

namespace App\Jobs;

use App\Actions\Agents\ExecuteAgentTask;
use App\Enums\AgentTaskStatus;
use App\Models\AgentTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessAgentTask implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(public AgentTask $task) {}

    public function handle(ExecuteAgentTask $executeAgentTask): void
    {
        $executeAgentTask->handle($this->task);
    }

    public function failed(?Throwable $exception): void
    {
        $this->task->refresh();

        if ($this->task->status === AgentTaskStatus::Completed) {
            return;
        }

        $this->task->forceFill([
            'status' => AgentTaskStatus::Failed,
            'locked_at' => null,
            'last_error' => $exception?->getMessage() ?: 'Agent task job failed before completing.',
        ])->save();

        $this->task->syncStatusPost();
    }
}
