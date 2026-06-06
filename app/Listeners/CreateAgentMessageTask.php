<?php

namespace App\Listeners;

use App\Enums\AgentTaskStatus;
use App\Events\MessageSent;
use App\Models\Principal;

class CreateAgentMessageTask
{
    /**
     * Handle the event.
     */
    public function handle(MessageSent $event): void
    {
        $message = $event->message->loadMissing('recipient.agent');
        $recipient = $message->recipient;

        if (! $recipient || $recipient->type !== Principal::TypeAgent || ! $recipient->agent) {
            return;
        }

        $recipient->agent->tasks()->firstOrCreate([
            'message_id' => $message->id,
            'event_type' => 'message_received',
        ], [
            'status' => AgentTaskStatus::Pending,
            'available_at' => now(),
        ]);
    }
}
