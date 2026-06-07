<?php

namespace App\Enums;

enum ReasoningEffort: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            ReasoningEffort::Low => 'Low',
            ReasoningEffort::Medium => 'Medium',
            ReasoningEffort::High => 'High',
        };
    }
}
