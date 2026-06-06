<?php

namespace App\Listeners;

use App\Events\MessageSent;
use App\Models\Principal;
use App\Notifications\MessageReceived;

class CreateMessageNotification
{
    /**
     * Handle the event.
     */
    public function handle(MessageSent $event): void
    {
        $message = $event->message->loadMissing(['recipient.user', 'sender']);
        $recipient = $message->recipient;

        if (! $recipient || $recipient->type !== Principal::TypeUser || ! $recipient->user) {
            return;
        }

        if ($message->sender_principal_id === $recipient->id) {
            return;
        }

        $recipient->user->notify(new MessageReceived($message));
    }
}
