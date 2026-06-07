<?php

namespace App\Mcp\Concerns;

use App\Mcp\TopicForgeUris;
use App\Models\AgentTask;
use App\Models\Post;

trait FormatsMcpPayloads
{
    /**
     * @return array<string, mixed>
     */
    protected function postPayload(Post $post, bool $includeBody = false): array
    {
        $post->loadMissing(['topic.workspace', 'sender.user', 'sender.agent', 'assignedAgents']);

        $payload = [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'status' => $post->status->value,
            'sender_principal_id' => $post->sender_principal_id,
            'sender' => $post->sender ? [
                'id' => $post->sender->id,
                'type' => $post->sender->type,
                'name' => $post->sender->label(),
            ] : null,
            'assigned_agents' => $post->assignedAgents
                ->map(fn ($agent): array => [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'slug' => $agent->slug,
                ])
                ->values()
                ->all(),
            'resource_uri' => $this->postResourceUri($post),
        ];

        if ($includeBody) {
            $payload['body'] = $post->body;
        } else {
            $payload['has_body'] = filled($post->body);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function agentTaskPayload(AgentTask $task, bool $includePostBody = false): array
    {
        $task->loadMissing(['agent.workspace', 'post.topic.workspace', 'post.sender.user', 'post.sender.agent']);

        return [
            'id' => $task->id,
            'event_type' => $task->event_type,
            'status' => $task->status->value,
            'priority' => $task->priority,
            'available_at' => $task->available_at?->toIso8601String(),
            'locked_at' => $task->locked_at?->toIso8601String(),
            'attempts' => $task->attempts,
            'last_error' => $task->last_error,
            'resource_uri' => $this->agentTaskResourceUri($task),
            'post' => $this->postPayload($task->post, includeBody: $includePostBody),
        ];
    }

    protected function postResourceUri(Post $post): string
    {
        $post->loadMissing('topic.workspace');

        return TopicForgeUris::post($post);
    }

    protected function agentTaskResourceUri(AgentTask $task): string
    {
        $task->loadMissing('agent.workspace');

        return TopicForgeUris::agentTask($task);
    }
}
