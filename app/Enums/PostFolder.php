<?php

namespace App\Enums;

enum PostFolder: string
{
    case Feed = 'feed';
    case Drafts = 'drafts';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Feed => __('Feed'),
            self::Drafts => __('Drafts'),
            self::Archived => __('Archived'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Feed => 'rss',
            self::Drafts => 'document',
            self::Archived => 'archive-box',
        };
    }

    public function status(): PostStatus
    {
        return match ($this) {
            self::Feed => PostStatus::Published,
            self::Drafts => PostStatus::Draft,
            self::Archived => PostStatus::Archived,
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
