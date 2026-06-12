<?php

namespace App\Console\Commands;

use App\Enums\AgentTaskStatus;
use App\Models\AgentTask;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agent-tasks:fail-stale {--minutes=10 : Minutes after which a processing task is considered stale}')]
#[Description('Mark processing agent tasks as failed when their worker disappeared before completion.')]
class FailStaleAgentTasks extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $cutoff = now()->subMinutes($minutes);
        $failed = 0;

        AgentTask::query()
            ->with(['agent.workspace', 'post.thread', 'statusPost'])
            ->where('status', AgentTaskStatus::Processing)
            ->where('locked_at', '<=', $cutoff)
            ->each(function (AgentTask $task) use (&$failed): void {
                $task->forceFill([
                    'status' => AgentTaskStatus::Failed,
                    'locked_at' => null,
                    'last_error' => 'Agent task timed out or the worker exited before completing.',
                ])->save();

                $task->syncStatusPost();
                $failed++;
            });

        $this->components->info("Marked {$failed} stale agent task(s) as failed.");

        return self::SUCCESS;
    }
}
