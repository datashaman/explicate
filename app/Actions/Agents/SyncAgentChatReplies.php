<?php

namespace App\Actions\Agents;

use App\Ai\Agents\ExplicateAgentRouter;
use App\Enums\AgentTaskStatus;
use App\Enums\PostStatus;
use App\Jobs\ProcessAgentTask;
use App\Jobs\RouteThreadAgentReplies;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\Post;
use App\Models\Principal;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class SyncAgentChatReplies
{
    public function handle(Post $post): void
    {
        $post->loadMissing(['sender.agent', 'thread.workspace']);

        if ($post->sender?->type === Principal::TypeAgent) {
            $mentionedAgents = $post->mentionedAgents()
                ->reject(fn (Agent $agent): bool => $agent->is($post->sender?->agent))
                ->values();

            $this->removeStaleMentionSummons($post, $mentionedAgents);
            $this->removeStaleRoutedSummons($post, Collection::make());

            if ($post->status === PostStatus::Published && $mentionedAgents->isNotEmpty()) {
                $this->summonAgents($post, $mentionedAgents, AgentTask::EventChatSummoned);
            }

            return;
        }

        $mentionedAgents = $post->mentionedAgents();

        $this->removeStaleMentionSummons($post, $mentionedAgents);
        $this->removeStaleRoutedSummons($post, Collection::make());

        if ($post->status !== PostStatus::Published) {
            return;
        }

        if ($mentionedAgents->isNotEmpty()) {
            $this->summonAgents($post, $mentionedAgents, AgentTask::EventChatSummoned);

            return;
        }

        if ($this->hasRoutingCandidates($post)) {
            RouteThreadAgentReplies::dispatch($post);
        }
    }

    public function route(Post $post): void
    {
        $post->loadMissing(['sender.agent', 'thread.workspace']);

        if ($post->status !== PostStatus::Published || $post->mentionedAgents()->isNotEmpty()) {
            $this->removeStaleRoutedSummons($post, Collection::make());

            return;
        }

        $routedAgents = $this->routeThreadReply($post);

        $this->removeStaleRoutedSummons($post, $routedAgents);
        $this->summonAgents($post, $routedAgents, AgentTask::EventThreadRouted);
    }

    /**
     * @param  Collection<int, Agent>  $mentionedAgents
     */
    private function removeStaleMentionSummons(Post $post, Collection $mentionedAgents): void
    {
        $tasksToRemove = $post->agentTasks()
            ->where('event_type', AgentTask::EventChatSummoned);

        if ($mentionedAgents->isNotEmpty()) {
            $tasksToRemove->whereNotIn('agent_id', $mentionedAgents->pluck('id')->all());
        }

        $tasksToRemove->get()->each->delete();
    }

    /**
     * @param  Collection<int, Agent>  $routedAgents
     */
    private function removeStaleRoutedSummons(Post $post, Collection $routedAgents): void
    {
        $tasksToRemove = $post->agentTasks()
            ->where('event_type', AgentTask::EventThreadRouted);

        if ($routedAgents->isNotEmpty()) {
            $tasksToRemove->whereNotIn('agent_id', $routedAgents->pluck('id')->all());
        }

        $tasksToRemove->get()->each->delete();
    }

    /**
     * @param  Collection<int, Agent>  $agents
     */
    private function summonAgents(Post $post, Collection $agents, string $eventType): void
    {
        $agents->each(function (Agent $agent) use ($post, $eventType): void {
            $task = $post->agentTasks()->firstOrCreate([
                'agent_id' => $agent->id,
                'event_type' => $eventType,
            ], [
                'status' => AgentTaskStatus::Pending,
                'available_at' => now(),
            ]);

            if ($task->status !== AgentTaskStatus::Completed || ! $task->status_post_id) {
                $task->syncStatusPost();
            }

            if ($task->status === AgentTaskStatus::Pending) {
                ProcessAgentTask::dispatch($task);
            }
        });
    }

    /**
     * @return Collection<int, Agent>
     */
    private function routeThreadReply(Post $post): Collection
    {
        if (! $post->thread || $post->sender?->type === Principal::TypeAgent) {
            return Collection::make();
        }

        $candidateAgents = $this->participatingAgentsBefore($post);

        if ($candidateAgents->isEmpty()) {
            return Collection::make();
        }

        $response = ExplicateAgentRouter::make(
            post: $post,
            candidateAgents: $candidateAgents,
        )->prompt($this->routerPrompt($post, $candidateAgents));

        $agentsBySlug = $candidateAgents->keyBy('slug');

        return Collection::make($response['responses'] ?? [])
            ->pluck('agent_slug')
            ->filter(fn (mixed $slug): bool => is_string($slug) && $agentsBySlug->has($slug))
            ->unique()
            ->map(fn (string $slug): Agent => $agentsBySlug->get($slug))
            ->values();
    }

    private function hasRoutingCandidates(Post $post): bool
    {
        if (! $post->thread || $post->sender?->type === Principal::TypeAgent) {
            return false;
        }

        return $this->participatingAgentsBefore($post)->isNotEmpty();
    }

    /**
     * @return EloquentCollection<int, Agent>
     */
    private function participatingAgentsBefore(Post $post): EloquentCollection
    {
        $agentIds = $post->thread
            ->posts()
            ->where('posts.id', '<', $post->id)
            ->whereHas('sender', fn ($query) => $query->where('type', Principal::TypeAgent))
            ->with('sender.agent')
            ->get()
            ->pluck('sender.agent.id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($agentIds === []) {
            return new EloquentCollection;
        }

        return $post->thread->workspace
            ->agents()
            ->with('latestVersion')
            ->whereIn('id', $agentIds)
            ->get();
    }

    /**
     * @param  EloquentCollection<int, Agent>  $candidateAgents
     */
    private function routerPrompt(Post $post, EloquentCollection $candidateAgents): string
    {
        $agents = $candidateAgents
            ->map(fn (Agent $agent): string => "- {$agent->name} (@{$agent->slug})")
            ->implode("\n");

        $sender = $post->sender?->label() ?? 'Unknown sender';

        return <<<PROMPT
Current post:
{$sender}: {$post->body}

Agents participating in this thread:
{$agents}

Return the agents, if any, that should respond to the current post in the order they should respond.
PROMPT;
    }
}
