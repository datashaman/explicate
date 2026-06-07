<?php

namespace App\Enums;

enum ReasoningEffort: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            ReasoningEffort::Low => 'Low',
            ReasoningEffort::Medium => 'Medium',
            ReasoningEffort::High => 'High',
        };
    }
}
