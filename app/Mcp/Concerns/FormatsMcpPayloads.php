<?php

namespace App\Mcp\Concerns;

use App\Mcp\ExplicateUris;
use App\Models\AgentTask;
use App\Models\Post;
use App\Models\Thread;
use App\Models\Workspace;

trait FormatsMcpPayloads
{
    /**
     * @return array<string, mixed>
     */
    protected function postSummaryPayload(Post $post): array
    {
        $post->loadMissing(['thread.topic', 'thread.workspace', 'sender.user', 'sender.agent']);

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
            'thread' => $this->threadSummaryPayload($post->thread),
            'resource_uri' => $this->postResourceUri($post),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function threadSummaryPayload(Thread $thread): array
    {
        $thread->loadMissing(['workspace', 'topic', 'latestPost.sender.user', 'latestPost.sender.agent']);
        $postsCount = (int) ($thread->posts_count ?? $thread->posts()->count());

        return [
            'id' => $thread->id,
            'title' => $thread->title,
            'slug' => $thread->slug,
            'summary' => $thread->summary,
            'posts_count' => $postsCount,
            'updated_at' => $thread->updated_at?->toIso8601String(),
            'topic' => $thread->topic ? [
                ...$thread->topic->only(['id', 'name', 'slug']),
                'resource_uri' => ExplicateUris::topic($thread->topic),
            ] : null,
            'latest_post' => $thread->latestPost ? [
                'id' => $thread->latestPost->id,
                'ulid' => $thread->latestPost->ulid,
                'preview' => $thread->latestPost->preview(),
                'status' => $thread->latestPost->status->value,
                'sender_principal_id' => $thread->latestPost->sender_principal_id,
            ] : null,
            'resource_uri' => ExplicateUris::thread($thread),
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
        $task->loadMissing(['agent.workspace', 'post.thread.workspace', 'post.sender.user', 'post.sender.agent']);

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
     * @param  array{name: string, path: string, type: string, content?: string|null}  $entry
     * @return array<string, mixed>
     */
    protected function workspaceFilePayload(array $entry, Workspace $workspace, bool $includeContent = false): array
    {
        $payload = [
            'type' => $entry['type'],
            'name' => $entry['name'],
            'path' => $entry['path'],
            'resource_uri' => ExplicateUris::workspaceFile($workspace, $entry['path']),
            'dashboard_url' => route('dashboard', [
                'action' => 'files',
                'file' => $entry['path'],
                'panel' => 'posts',
            ]),
        ];

        if ($includeContent) {
            $payload['content'] = $entry['content'] ?? null;
        }

        return $payload;
    }

    protected function postResourceUri(Post $post): string
    {
        $post->loadMissing('thread.workspace');

        return ExplicateUris::post($post);
    }

    protected function agentTaskResourceUri(AgentTask $task): string
    {
        $task->loadMissing('agent.workspace');

        return ExplicateUris::agentTask($task);
    }
}
