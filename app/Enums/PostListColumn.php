<?php

namespace App\Enums;

enum PostListColumn: string
{
    case Post = 'post';
    case Sender = 'sender';
    case Topic = 'topic';
    case Sent = 'sent';
    case Saved = 'saved';
    case Attachments = 'attachments';

    /**
     * @return array{key: string, label: string, class: string}
     */
    public function toColumn(?string $label = null, ?string $class = null): array
    {
        return [
            'key' => $this->value,
            'label' => $label ?? $this->label(),
            'class' => $class ?? $this->class(),
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::Post => __('Post'),
            self::Sender => __('Sender'),
            self::Topic => __('Topic'),
            self::Sent => __('Sent'),
            self::Saved => __('Saved'),
            self::Attachments => __('Files'),
        };
    }

    public function class(): string
    {
        return match ($this) {
            self::Post => 'min-w-0 flex-1',
            self::Sender, self::Topic, self::Sent, self::Saved => 'w-28 shrink-0',
            self::Attachments => 'w-12 shrink-0 justify-center',
        };
    }
}
