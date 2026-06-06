<?php

namespace App\Mcp\Concerns;

use App\Models\AgentTask;
use App\Models\Message;

trait FormatsMcpPayloads
{
    /**
     * @return array<string, mixed>
     */
    protected function messagePayload(Message $message, bool $includeBody = false): array
    {
        $message->loadMissing(['topic.workspace', 'sender.user', 'sender.agent', 'recipient.user', 'recipient.agent', 'assignedAgents']);

        $payload = [
            'id' => $message->id,
            'title' => $message->title,
            'slug' => $message->slug,
            'status' => $message->status->value,
            'sender_principal_id' => $message->sender_principal_id,
            'sender' => $message->sender ? [
                'id' => $message->sender->id,
                'type' => $message->sender->type,
                'name' => $message->sender->label(),
            ] : null,
            'recipient_principal_id' => $message->recipient_principal_id,
            'recipient' => $message->recipient ? [
                'id' => $message->recipient->id,
                'type' => $message->recipient->type,
                'name' => $message->recipient->label(),
            ] : null,
            'assigned_agents' => $message->assignedAgents
                ->map(fn ($agent): array => [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'slug' => $agent->slug,
                ])
                ->values()
                ->all(),
            'resource_uri' => $this->messageResourceUri($message),
        ];

        if ($includeBody) {
            $payload['body'] = $message->body;
        } else {
            $payload['has_body'] = filled($message->body);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function agentTaskPayload(AgentTask $task, bool $includeMessageBody = false): array
    {
        $task->loadMissing(['agent.workspace', 'message.topic.workspace', 'message.sender.user', 'message.sender.agent', 'message.recipient.user', 'message.recipient.agent']);

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
            'message' => $this->messagePayload($task->message, includeBody: $includeMessageBody),
        ];
    }

    protected function messageResourceUri(Message $message): string
    {
        $message->loadMissing('topic.workspace');

        return "topic-forge://workspaces/{$message->topic->workspace->slug}/topics/{$message->topic->slug}/messages/{$message->slug}";
    }

    protected function agentTaskResourceUri(AgentTask $task): string
    {
        $task->loadMissing('agent.workspace');

        return "topic-forge://workspaces/{$task->agent->workspace->slug}/agents/{$task->agent->slug}/tasks/{$task->id}";
    }
}
