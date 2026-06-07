<?php

namespace App\Enums;

enum PostFolder: string
{
    case Feed = 'feed';
    case Drafts = 'drafts';
    case Bin = 'bin';

    public function label(): string
    {
        return match ($this) {
            self::Feed => __('Feed'),
            self::Drafts => __('Drafts'),
            self::Bin => __('Bin'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Feed => 'rss',
            self::Drafts => 'document',
            self::Bin => 'trash',
        };
    }

    public function status(): ?PostStatus
    {
        return match ($this) {
            self::Feed => PostStatus::Published,
            self::Drafts => PostStatus::Draft,
            self::Bin => null,
        };
    }

    public function dateKey(): string
    {
        return $this === self::Drafts ? PostListColumn::Saved->value : PostListColumn::Sent->value;
    }

    public function dateLabel(): string
    {
        return $this === self::Drafts ? __('Saved') : __('Posted');
    }
}
