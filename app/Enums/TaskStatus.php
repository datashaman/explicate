<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::InProgress => __('In progress'),
            self::Done => __('Done'),
            self::Blocked => __('Blocked'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'zinc',
            self::InProgress => 'blue',
            self::Done => 'green',
            self::Blocked => 'red',
        };
    }
}
