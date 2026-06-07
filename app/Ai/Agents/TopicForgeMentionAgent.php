<?php

namespace App\Ai\Agents;

use App\Ai\Tools\ManageAgentTaskListTool;
use App\Ai\Tools\TopicForgeToolFactory;
use App\Models\AgentTask;
use App\Models\Post;
use App\Models\Principal;
use App\Models\User;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Stringable;

class TopicForgeMentionAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    public function __construct(
        private readonly AgentTask $task,
        private readonly User $toolUser,
        private readonly TopicForgeToolFactory $toolFactory,
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
            'post.thread.parentPost.sender.user',
            'post.thread.parentPost.sender.agent',
            'post.startedThread.parentPost.sender.user',
            'post.startedThread.parentPost.sender.agent',
            'statusPost',
        ]);

        return $this->task->post
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
            ...$this->toolFactory->forAgentTask($this->toolUser, $this->task->agent->workspace),
        ];
    }

    private function identityInstructions(): string
    {
        $agent = $this->task->agent;

        return <<<INSTRUCTIONS
Topic Forge identity:
- You are {$agent->name} (@{$agent->slug}).
- When the conversation mentions @{$agent->slug}, "{$agent->name}", "you", or "the agent", treat that as referring to you unless context clearly says otherwise.
- Thread messages include sender labels so you can tell who said what.
- Do not prefix your reply with your name, your @slug, or any speaker label. The UI already shows who sent the reply.
INSTRUCTIONS;
    }

    private function artifactInstructions(): string
    {
        return <<<'INSTRUCTIONS'
Topic Forge artifact policy:
- Keep the post reply concise. Use it to summarize what you did, mention important file paths, and ask short follow-up questions.
- Use the workspace filesystem tools for substantial artifacts such as specifications, plans, reports, code, research notes, or any response that would otherwise be long.
- Prefer creating or updating a well-named Markdown file with write-file, then reference that path in your reply instead of pasting large swaths of text into the post.
- When you refer to a workspace file in a post reply, use a Markdown link with the file path as the label and the file tool response's dashboard_url as the href, for example [docs/spec.md](dashboard_url).
INSTRUCTIONS;
    }

    private function taskInstructions(): string
    {
        return <<<'INSTRUCTIONS'
Topic Forge task list policy:
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
