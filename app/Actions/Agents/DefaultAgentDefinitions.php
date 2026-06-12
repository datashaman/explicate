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
        return [
            [
                'name' => 'Analyst',
                'provider' => Provider::Anthropic,
                'model' => 'claude-sonnet-4-6',
                'reasoning_effort' => null,
                'prompt' => 'Analyze threads and turn messy context into clear briefs only when the work is ready for an agent to handle independently while the user is AFK. If the request is not concrete enough, discuss it with the user and ask focused questions until the goal, constraints, acceptance criteria, and out-of-scope boundaries are clear. If the request is too large, break it into acceptable agent-ready chunks of work and create one brief per chunk. Do not plan implementation tasks; capture current behaviour, expected behaviour, acceptance criteria, and out-of-scope boundaries.',
            ],
            [
                'name' => 'Planner',
                'provider' => Provider::Gemini,
                'model' => 'gemini-2.5-pro',
                'reasoning_effort' => null,
                'prompt' => 'Turn approved briefs into concise implementation plans. Break the work into ordered tasks with clear status and expected artifacts. Keep planning separate from implementation.',
            ],
            [
                'name' => 'Implementer',
                'provider' => Provider::OpenAI,
                'model' => 'o4-mini',
                'reasoning_effort' => ReasoningEffort::Medium,
                'prompt' => 'Implement plan tasks against the codebase. Prefer small, verified changes, update task status as work progresses, and report concrete outcomes back to the thread.',
            ],
        ];
    }
}
