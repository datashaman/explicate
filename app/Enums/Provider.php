<?php

namespace App\Enums;

enum Provider: string
{
    case Anthropic = 'anthropic';
    case OpenAI = 'openai';
    case Google = 'google';
    case Groq = 'groq';

    public function label(): string
    {
        return match ($this) {
            Provider::Anthropic => 'Anthropic',
            Provider::OpenAI => 'OpenAI',
            Provider::Google => 'Google',
            Provider::Groq => 'Groq',
        };
    }

    /** @return list<string> */
    public function models(): array
    {
        return match ($this) {
            Provider::Anthropic => [
                'claude-opus-4-8',
                'claude-sonnet-4-6',
                'claude-haiku-4-5-20251001',
            ],
            Provider::OpenAI => [
                'gpt-4o',
                'gpt-4o-mini',
                'o1',
                'o3',
                'o3-mini',
                'o4-mini',
            ],
            Provider::Google => [
                'gemini-2.5-pro',
                'gemini-2.5-flash',
                'gemini-2.0-flash',
            ],
            Provider::Groq => [
                'llama-3.3-70b-versatile',
                'llama-3.1-8b-instant',
                'mixtral-8x7b-32768',
            ],
        };
    }

    public function supportsReasoningEffort(): bool
    {
        return match ($this) {
            Provider::OpenAI => true,
            default => false,
        };
    }
}
