<?php

namespace App\Ai\Agents;

use App\Models\Agent as ExplicateAgent;
use App\Models\Post;
use App\Models\Principal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Stringable;

#[UseCheapestModel]
class ExplicateAgentRouter implements Agent, Conversational, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  EloquentCollection<int, ExplicateAgent>  $candidateAgents
     */
    public function __construct(
        private readonly Post $post,
        private readonly EloquentCollection $candidateAgents,
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You are Explicate's thread reply router.

Decide whether any existing agent participant should respond to the latest thread post.

Rules:
- Choose from the provided participating agents only.
- Return no agents for acknowledgements, thanks, status notes, casual commentary, or messages that do not need an agent.
- Return an agent when the latest post asks a direct follow-up, answers a question the agent asked, asks for a revision, reports a blocker the agent should handle, or otherwise clearly continues work with that agent.
- If several agents should respond, order them by who should speak first.
- Do not include agents just because they previously participated.
- Do not invent agent slugs.
INSTRUCTIONS;
    }

    /**
     * @return Message[]
     */
    public function messages(): iterable
    {
        $this->post->loadMissing('thread');

        return $this->post->thread
            ->conversationPosts()
            ->filter(fn (Post $post): bool => $post->id < $this->post->id)
            ->map(fn (Post $post): Message => $this->messageFor($post))
            ->values()
            ->all();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'responses' => $schema->array()
                ->items($schema->object(fn (JsonSchema $schema): array => [
                    'agent_slug' => $schema->string()->required(),
                    'reason' => $schema->string()->required(),
                ]))
                ->required(),
        ];
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
