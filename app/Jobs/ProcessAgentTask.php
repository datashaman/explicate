<?php

namespace App\Jobs;

use App\Actions\Agents\ExecuteAgentTask;
use App\Models\AgentTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAgentTask implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public AgentTask $task) {}

    public function handle(ExecuteAgentTask $executeAgentTask): void
    {
        $executeAgentTask->handle($this->task);
    }
}
