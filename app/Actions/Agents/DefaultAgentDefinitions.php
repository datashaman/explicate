<?php

namespace App\Actions\Agents;

use App\Enums\Provider;
use App\Enums\ReasoningEffort;

class DefaultAgentDefinitions
{
    /**
     * @return list<array{name: string, provider: Provider, model: string, reasoning_effort: ReasoningEffort|null, prompt: string}>
     */
    public function all(): array
    {
        return $this->forAvailableProviders(
            collect(Provider::cases())->map(fn (Provider $provider): string => $provider->value)->all(),
        );
    }

    /**
     * @param  list<string>  $availableProviders
     * @return list<array{name: string, provider: Provider, model: string, reasoning_effort: ReasoningEffort|null, prompt: string}>
     */
    public function forAvailableProviders(array $availableProviders): array
    {
        return collect($this->roles())
            ->map(fn (array $role): ?array => $this->resolveRole($role, $availableProviders))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array{name: string, preferred_providers: list<Provider>, models: array<string, string>, reasoning_effort: array<string, ReasoningEffort|null>, prompt: string}>
     */
    private function roles(): array
    {
        return [
            [
                'name' => 'Analyst',
                'preferred_providers' => [Provider::Anthropic, Provider::OpenAI, Provider::Gemini, Provider::Groq],
                'models' => [
                    Provider::Anthropic->value => 'claude-sonnet-4-6',
                    Provider::OpenAI->value => 'gpt-5.4',
                    Provider::Gemini->value => 'gemini-3.1-pro-preview',
                    Provider::Groq->value => 'openai/gpt-oss-120b',
                ],
                'reasoning_effort' => [],
                'prompt' => 'Analyze threads and turn messy context into clear briefs only when the work is ready for an agent to handle independently while the user is AFK. If the request is not concrete enough, discuss it with the user and ask focused questions until the goal, constraints, acceptance criteria, and out-of-scope boundaries are clear. If the request is too large, break it into acceptable agent-ready chunks of work and create one brief per chunk. Do not plan implementation tasks; capture current behaviour, expected behaviour, acceptance criteria, and out-of-scope boundaries.',
            ],
            [
                'name' => 'Planner',
                'preferred_providers' => [Provider::Gemini, Provider::Anthropic, Provider::OpenAI, Provider::Groq],
                'models' => [
                    Provider::Gemini->value => 'gemini-3.1-pro-preview',
                    Provider::Anthropic->value => 'claude-opus-4-8',
                    Provider::OpenAI->value => 'gpt-5.5',
                    Provider::Groq->value => 'openai/gpt-oss-120b',
                ],
                'reasoning_effort' => [],
                'prompt' => 'Turn approved briefs into concise implementation plans. Break the work into ordered tasks with clear status and expected artifacts. Keep planning separate from implementation.',
            ],
            [
                'name' => 'Implementer',
                'preferred_providers' => [Provider::OpenAI, Provider::Anthropic, Provider::Gemini, Provider::Groq],
                'models' => [
                    Provider::OpenAI->value => 'gpt-5.5',
                    Provider::Anthropic->value => 'claude-sonnet-4-6',
                    Provider::Gemini->value => 'gemini-3.1-pro-preview',
                    Provider::Groq->value => 'openai/gpt-oss-120b',
                ],
                'reasoning_effort' => [
                    Provider::OpenAI->value => ReasoningEffort::Medium,
                ],
                'prompt' => 'Implement plan tasks against the codebase. Prefer small, verified changes, update task status as work progresses, and report concrete outcomes back to the thread.',
            ],
        ];
    }

    /**
     * @param  array{name: string, preferred_providers: list<Provider>, models: array<string, string>, reasoning_effort: array<string, ReasoningEffort|null>, prompt: string}  $role
     * @param  list<string>  $availableProviders
     * @return array{name: string, provider: Provider, model: string, reasoning_effort: ReasoningEffort|null, prompt: string}|null
     */
    private function resolveRole(array $role, array $availableProviders): ?array
    {
        $provider = collect($role['preferred_providers'])
            ->first(fn (Provider $provider): bool => in_array($provider->value, $availableProviders, true));

        if (! $provider instanceof Provider) {
            return null;
        }

        return [
            'name' => $role['name'],
            'provider' => $provider,
            'model' => $role['models'][$provider->value] ?? $provider->models()[0],
            'reasoning_effort' => $role['reasoning_effort'][$provider->value] ?? null,
            'prompt' => $role['prompt'],
        ];
    }
}
