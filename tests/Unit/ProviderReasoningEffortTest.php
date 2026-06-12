<?php

use App\Enums\Provider;
use App\Enums\ReasoningEffort;

test('it maps reasoning effort to provider specific options', function (
    Provider $provider,
    string $model,
    ReasoningEffort $effort,
    array $expectedOptions,
) {
    expect($provider->supportsReasoningEffort($model))->toBeTrue()
        ->and($provider->reasoningEffortOptions($model, $effort))->toBe($expectedOptions);
})->with([
    'openai gpt 5' => [
        Provider::OpenAI,
        'gpt-5.5',
        ReasoningEffort::High,
        [
            'reasoning' => [
                'effort' => 'high',
            ],
        ],
    ],
    'anthropic adaptive thinking' => [
        Provider::Anthropic,
        'claude-opus-4-8',
        ReasoningEffort::Medium,
        [
            'output_config' => [
                'effort' => 'medium',
            ],
            'thinking' => [
                'type' => 'adaptive',
            ],
        ],
    ],
    'anthropic fable effort only' => [
        Provider::Anthropic,
        'claude-fable-5',
        ReasoningEffort::Low,
        [
            'output_config' => [
                'effort' => 'low',
            ],
        ],
    ],
    'gemini 3 thinking level' => [
        Provider::Gemini,
        'gemini-3.5-flash',
        ReasoningEffort::Low,
        [
            'thinkingConfig' => [
                'thinkingLevel' => 'low',
            ],
        ],
    ],
    'gemini 2.5 thinking budget' => [
        Provider::Gemini,
        'gemini-2.5-flash',
        ReasoningEffort::High,
        [
            'thinkingConfig' => [
                'thinkingBudget' => 24576,
            ],
        ],
    ],
    'groq gpt oss reasoning effort' => [
        Provider::Groq,
        'openai/gpt-oss-120b',
        ReasoningEffort::Medium,
        [
            'reasoning_effort' => 'medium',
        ],
    ],
]);

test('it omits reasoning effort options for unsupported models', function (
    Provider $provider,
    string $model,
) {
    expect($provider->supportsReasoningEffort($model))->toBeFalse()
        ->and($provider->reasoningEffortOptions($model, ReasoningEffort::High))->toBe([]);
})->with([
    'openai non reasoning model' => [Provider::OpenAI, 'gpt-4.1'],
    'anthropic haiku without adaptive effort' => [Provider::Anthropic, 'claude-haiku-4-5'],
    'groq llama' => [Provider::Groq, 'llama-3.3-70b-versatile'],
]);
