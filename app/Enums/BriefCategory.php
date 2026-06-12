<?php

namespace App\Enums;

enum BriefCategory: string
{
    case Bug = 'bug';
    case Feature = 'feature';

    public function label(): string
    {
        return match ($this) {
            self::Bug => __('Bug'),
            self::Feature => __('Feature'),
        };
    }
}
