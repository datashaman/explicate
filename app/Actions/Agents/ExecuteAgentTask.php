<?php

namespace App\Actions\Agents;

use App\Ai\Tools\TopicForgeToolFactory;
use App\Enums\AgentTaskStatus;
use App\Models\AgentTask;
use App\Models\Post;
use App\Models\User;
use Laravel\Ai\Enums\Lab;
use RuntimeException;
use Throwable;

use function Laravel\Ai\agent as laravelAiAgent;

class ExecuteAgentTask
{
    public function __construct(private readonly TopicForgeToolFactory $toolFactory) {}

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

            $response = laravelAiAgent(
                instructions: $this->instructionsFor($version->prompt),
                tools: $this->toolFactory->forAgentTask(
                    $this->toolUserFor($task),
                    $task->agent->workspace,
                ),
            )
                ->prompt(
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

    private function instructionsFor(?string $agentInstructions): string
    {
        return trim(implode("\n\n", array_filter([
            trim((string) $agentInstructions),
            <<<'INSTRUCTIONS'
Topic Forge artifact policy:
- Keep the post reply concise. Use it to summarize what you did, mention important file paths, and ask short follow-up questions.
- Use the workspace filesystem tools for substantial artifacts such as specifications, plans, reports, code, research notes, or any response that would otherwise be long.
- Prefer creating or updating a well-named Markdown file with write-file, then reference that path in your reply instead of pasting large swaths of text into the post.
- When you refer to a workspace file in a post reply, use a Markdown link with the file path as the label and the file tool response's dashboard_url as the href, for example [docs/spec.md](dashboard_url).
INSTRUCTIONS,
        ])));
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
