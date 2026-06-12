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

    public function supportsReasoningEffort(?string $model = null): bool
    {
        if ($model === null) {
            return match ($this) {
                Provider::Anthropic, Provider::OpenAI, Provider::Gemini, Provider::Groq => true,
            };
        }

        return match ($this) {
            Provider::Anthropic => in_array($model, [
                'claude-fable-5',
                'claude-opus-4-8',
                'claude-sonnet-4-6',
            ], true),
            Provider::OpenAI => str_starts_with($model, 'gpt-5'),
            Provider::Gemini => str_starts_with($model, 'gemini-3')
                || str_starts_with($model, 'gemini-2.5'),
            Provider::Groq => in_array($model, [
                'openai/gpt-oss-120b',
                'openai/gpt-oss-20b',
            ], true),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function reasoningEffortOptions(string $model, ReasoningEffort $effort): array
    {
        if (! $this->supportsReasoningEffort($model)) {
            return [];
        }

        return match ($this) {
            Provider::Anthropic => $this->anthropicReasoningEffortOptions($model, $effort),
            Provider::OpenAI => [
                'reasoning' => [
                    'effort' => $effort->value,
                ],
            ],
            Provider::Gemini => str_starts_with($model, 'gemini-3')
                ? [
                    'thinkingConfig' => [
                        'thinkingLevel' => $effort->value,
                    ],
                ]
                : [
                    'thinkingConfig' => [
                        'thinkingBudget' => $this->geminiThinkingBudget($effort),
                    ],
                ],
            Provider::Groq => [
                'reasoning_effort' => $effort->value,
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function anthropicReasoningEffortOptions(string $model, ReasoningEffort $effort): array
    {
        $options = [
            'output_config' => [
                'effort' => $effort->value,
            ],
        ];

        if ($model !== 'claude-fable-5') {
            $options['thinking'] = ['type' => 'adaptive'];
        }

        return $options;
    }

    private function geminiThinkingBudget(ReasoningEffort $effort): int
    {
        return match ($effort) {
            ReasoningEffort::Low => 1024,
            ReasoningEffort::Medium => 8192,
            ReasoningEffort::High => 24576,
        };
    }
}
