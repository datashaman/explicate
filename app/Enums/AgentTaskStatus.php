<?php

namespace App\Enums;

enum AgentTaskStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('queued'),
            self::Processing => __('working'),
            self::Completed => __('replied'),
            self::Failed => __('failed'),
        };
    }
}
