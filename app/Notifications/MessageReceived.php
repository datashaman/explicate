<?php

namespace App\Notifications;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MessageReceived extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public Message $message) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $message = $this->message->loadMissing(['sender.user', 'sender.agent', 'topic']);

        return [
            'message_id' => $message->id,
            'message_ulid' => $message->ulid,
            'topic_id' => $message->topic_id,
            'topic_name' => $message->topic->name,
            'title' => $message->title,
            'sender_name' => $message->sender?->label(),
        ];
    }
}
