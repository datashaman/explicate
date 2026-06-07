<?php

namespace App\Mcp\Concerns;

use App\Mcp\TopicForgeUris;
use App\Models\AgentTask;
use App\Models\Post;
use App\Models\WorkspaceFile;

trait FormatsMcpPayloads
{
    /**
     * @return array<string, mixed>
     */
    protected function postSummaryPayload(Post $post): array
    {
        $post->loadMissing(['topic.workspace', 'sender.user', 'sender.agent']);

        return [
            'id' => $post->id,
            'ulid' => $post->ulid,
            'preview' => $post->preview(),
            'status' => $post->status->value,
            'sender_principal_id' => $post->sender_principal_id,
            'sender' => $post->sender ? [
                'id' => $post->sender->id,
                'type' => $post->sender->type,
                'name' => $post->sender->label(),
            ] : null,
            'resource_uri' => $this->postResourceUri($post),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function postPayload(Post $post): array
    {
        return [
            ...$this->postSummaryPayload($post),
            'body' => $post->body,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function agentTaskPayload(AgentTask $task): array
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
            'post' => $this->postSummaryPayload($task->post),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function agentTaskWithPostPayload(AgentTask $task): array
    {
        return [
            ...$this->agentTaskPayload($task),
            'post' => $this->postPayload($task->post),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function workspaceFilePayload(WorkspaceFile $file, bool $includeContent = false): array
    {
        $file->loadMissing('workspace');

        $payload = [
            'id' => $file->id,
            'type' => $file->type->value,
            'name' => $file->name,
            'path' => $file->path,
            'parent_id' => $file->parent_id,
            'resource_uri' => TopicForgeUris::workspaceFile($file),
            'dashboard_url' => route('dashboard', [
                'action' => 'files',
                'file' => $file->id,
                'panel' => 'posts',
            ]),
        ];

        if ($includeContent) {
            $payload['content'] = $file->content;
        }

        return $payload;
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
