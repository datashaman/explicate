<?php

namespace App\Listeners;

use App\Events\PostSent;
use App\Models\Principal;
use App\Notifications\PostReceived;

class CreatePostNotification
{
    /**
     * Handle the event.
     */
    public function handle(PostSent $event): void
    {
        $post = $event->post->loadMissing(['recipient.user', 'sender']);
        $recipient = $post->recipient;

        if (! $recipient || $recipient->type !== Principal::TypeUser || ! $recipient->user) {
            return;
        }

        if ($post->sender_principal_id === $recipient->id) {
            return;
        }

        $recipient->user->notify(new PostReceived($post));
    }
}
