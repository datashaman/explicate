<?php

namespace App\Notifications;

use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PostReceived extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public Post $post) {}

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
        $post = $this->post->loadMissing(['sender.user', 'sender.agent', 'topic']);

        return [
            'post_id' => $post->id,
            'post_ulid' => $post->ulid,
            'topic_id' => $post->topic_id,
            'topic_name' => $post->topic->name,
            'title' => $post->title,
            'sender_name' => $post->sender?->label(),
        ];
    }
}
