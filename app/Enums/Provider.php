<?php

namespace App\Enums;

enum Provider: string
{
    case Anthropic = 'anthropic';
    case OpenAI = 'openai';
    case Gemini = 'gemini';
    case Groq = 'groq';

    public function label(): string
    {
        return match ($this) {
            Provider::Anthropic => 'Anthropic',
            Provider::OpenAI => 'OpenAI',
            Provider::Gemini => 'Gemini',
            Provider::Groq => 'Groq',
        };
    }

    /** @return list<string> */
    public function models(): array
    {
        return match ($this) {
            Provider::Anthropic => [
                'claude-fable-5',
                'claude-opus-4-8',
                'claude-sonnet-4-6',
                'claude-haiku-4-5',
            ],
            Provider::OpenAI => [
                'gpt-5.5',
                'gpt-5.4',
                'gpt-5.4-mini',
                'gpt-5.4-nano',
            ],
            Provider::Gemini => [
                'gemini-3.5-flash',
                'gemini-3.1-pro-preview',
                'gemini-3-flash-preview',
                'gemini-3.1-flash-lite',
                'gemini-2.5-pro',
                'gemini-2.5-flash',
                'gemini-2.5-flash-lite',
            ],
            Provider::Groq => [
                'openai/gpt-oss-120b',
                'openai/gpt-oss-20b',
                'groq/compound',
                'groq/compound-mini',
                'meta-llama/llama-4-maverick-17b-128e-instruct',
                'meta-llama/llama-4-scout-17b-16e-instruct',
                'llama-3.3-70b-versatile',
                'llama-3.1-8b-instant',
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
