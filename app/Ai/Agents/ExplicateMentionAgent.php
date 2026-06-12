<?php

namespace App\Ai\Agents;

use App\Ai\Tools\ExplicateToolFactory;
use App\Ai\Tools\ManageAgentTaskListTool;
use App\Models\AgentTask;
use App\Models\Post;
use App\Models\Principal;
use App\Models\User;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Stringable;

class ExplicateMentionAgent implements Agent, Conversational, HasProviderOptions, HasTools
{
    use Promptable;

    public function __construct(
        private readonly AgentTask $task,
        private readonly User $toolUser,
        private readonly ExplicateToolFactory $toolFactory,
    ) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $this->task->loadMissing(['agent.latestVersion', 'agent.workspace']);

        return trim(implode("\n\n", array_filter([
            trim((string) $this->task->agent->latestVersion?->prompt),
            $this->identityInstructions(),
            $this->artifactInstructions(),
            $this->taskInstructions(),
        ])));
    }

    /**
     * Get the list of messages comprising the conversation so far.
     *
     * @return Message[]
     */
    public function messages(): iterable
    {
        $this->task->loadMissing([
            'post.sender.user',
            'post.sender.agent',
            'post.thread',
            'statusPost',
        ]);

        return $this->task->post->thread
            ->conversationPosts()
            ->filter(fn (Post $post): bool => $post->id !== $this->task->post_id)
            ->filter(fn (Post $post): bool => $post->id !== $this->task->status_post_id)
            ->filter(fn (Post $post): bool => $post->id < $this->task->post_id)
            ->map(fn (Post $post): Message => $this->messageFor($post))
            ->values()
            ->all();
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [
            new ManageAgentTaskListTool($this->task),
            ...$this->toolFactory->forAgentTask($this->toolUser, $this->task->agent->workspace, $this->task->agent),
        ];
    }

    /**
     * Get provider-specific generation options.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $this->task->loadMissing('agent.latestVersion');

        $version = $this->task->agent->latestVersion;
        $providerValue = $provider instanceof Lab ? $provider->value : $provider;

        if ($version === null || $providerValue !== $version->provider->value) {
            return [];
        }

        if (! $version->reasoning_effort) {
            return [];
        }

        return $version->provider->reasoningEffortOptions($version->model, $version->reasoning_effort);
    }

    private function identityInstructions(): string
    {
        $agent = $this->task->agent;

        return <<<INSTRUCTIONS
Explicate identity:
- You are {$agent->name} (@{$agent->slug}).
- When the conversation mentions @{$agent->slug}, "{$agent->name}", "you", or "the agent", treat that as referring to you unless context clearly says otherwise.
- Thread messages include sender labels so you can tell who said what.
- Do not prefix your reply with your name, your @slug, or any speaker label. The UI already shows who sent the reply.
INSTRUCTIONS;
    }

    private function artifactInstructions(): string
    {
        return <<<'INSTRUCTIONS'
Explicate artifact policy:
- Keep the post reply concise. Use it to summarize what you did, mention important file paths, and ask short follow-up questions.
- Use the workspace filesystem tools for substantial artifacts such as specifications, plans, reports, code, research notes, or any response that would otherwise be long.
- Prefer creating or updating a well-named Markdown file with write-file, then reference that path in your reply instead of pasting large swaths of text into the post.
- When you create or update a workspace file, the file tool response includes file.path and file.dashboard_url. In your post reply, link to that file with [file.path](file.dashboard_url), replacing both values with the exact strings returned by the tool.
- Never write placeholder hrefs such as dashboard_url, and do not link artifact titles to the current thread or dashboard unless that exact URL came from the file tool response.
INSTRUCTIONS;
    }

    private function taskInstructions(): string
    {
        return <<<'INSTRUCTIONS'
Explicate task list policy:
- Maintain a private task list with the task-list tool when the work has more than one step, has dependencies, or risks going off track.
- Add concrete next steps, check them off as you complete them, and remove items that are no longer relevant.
- Keep the task list short and actionable.
- Do not paste the task list into the post reply unless a brief summary is helpful.
INSTRUCTIONS;
    }

    private function messageFor(Post $post): Message
    {
        $post->loadMissing(['sender.user', 'sender.agent']);
        $content = $this->formatPostContent($post);

        if ($post->sender?->type === Principal::TypeAgent) {
            return new AssistantMessage($content);
        }

        return new UserMessage($content);
    }

    private function formatPostContent(Post $post): string
    {
        $sender = $post->sender;
        $label = $sender?->label() ?? 'Unknown sender';
        $agentSlug = $sender?->agent?->slug;
        $identity = $agentSlug ? "{$label} (@{$agentSlug})" : $label;

        return trim("{$identity}: {$post->body}");
    }
}
