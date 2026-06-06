<?php

use App\Actions\Agents\ExecuteAgentTask;
use App\Enums\AgentTaskStatus;
use App\Enums\MessageStatus;
use App\Enums\Provider;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\AgentVersion;
use App\Models\Message;
use App\Models\Topic;
use Laravel\Ai\Ai;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function () {
    [$this->user, $this->workspace] = userWithWorkspace();
    $this->topic = Topic::factory()->for($this->workspace)->create();
    $this->senderPrincipal = $this->workspace->principalForUser($this->user);
});

test('it executes a pending agent task through an anonymous laravel ai agent', function () {
    Ai::fakeAgent(AnonymousAgent::class, ['The agent response.'])->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    AgentVersion::factory()->for($agent)->create([
        'provider' => Provider::Gemini,
        'model' => 'gemini-2.5-flash',
        'prompt' => 'Answer as a concise researcher.',
    ]);

    $message = Message::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'title' => 'Research this',
        'body' => 'Find the latest internal context.',
        'status' => MessageStatus::Published,
    ]);
    $this->topic->agents()->attach($agent);
    $message->assignAgents([$agent->id]);

    $task = AgentTask::query()->whereBelongsTo($message)->sole();

    $reply = app(ExecuteAgentTask::class)->handle($task);

    Ai::assertAgentWasPrompted(AnonymousAgent::class, function (AgentPrompt $prompt): bool {
        return $prompt->prompt === "# Research this\n\nFind the latest internal context."
            && $prompt->model === 'gemini-2.5-flash'
            && $prompt->provider()->name() === 'gemini'
            && $prompt->agent->instructions() === 'Answer as a concise researcher.';
    });

    expect($reply)->not->toBeNull()
        ->and($reply->title)->toBe('Re: Research this')
        ->and($reply->body)->toBe('The agent response.')
        ->and($reply->status)->toBe(MessageStatus::Published)
        ->and($reply->sender_principal_id)->toBe($this->workspace->principalForAgent($agent)->id)
        ->and($reply->recipient_principal_id)->toBe($this->senderPrincipal->id)
        ->and($task->fresh()->status)->toBe(AgentTaskStatus::Completed)
        ->and($task->fresh()->attempts)->toBe(1)
        ->and($task->fresh()->locked_at)->toBeNull()
        ->and($task->fresh()->last_error)->toBeNull();
});

test('it marks a task failed when the agent has no executable version', function () {
    Ai::fakeAgent(AnonymousAgent::class)->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    $message = Message::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'status' => MessageStatus::Published,
    ]);
    $this->topic->agents()->attach($agent);
    $message->assignAgents([$agent->id]);

    $task = AgentTask::query()->whereBelongsTo($message)->sole();
    $exception = null;

    try {
        app(ExecuteAgentTask::class)->handle($task);
    } catch (RuntimeException $exception) {
    }

    expect($exception)->not->toBeNull()
        ->and($exception->getMessage())->toBe('Agent does not have a version to execute.');

    Ai::assertAgentNeverPrompted(AnonymousAgent::class);

    expect($task->fresh()->status)->toBe(AgentTaskStatus::Failed)
        ->and($task->fresh()->attempts)->toBe(1)
        ->and($task->fresh()->locked_at)->toBeNull()
        ->and($task->fresh()->last_error)->toBe('Agent does not have a version to execute.');
});

test('it ignores assigned draft message tasks until they are available', function () {
    Ai::fakeAgent(AnonymousAgent::class)->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    AgentVersion::factory()->for($agent)->create();
    $message = Message::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'status' => MessageStatus::Draft,
    ]);
    $this->topic->agents()->attach($agent);
    $message->assignAgents([$agent->id]);

    $task = AgentTask::query()->whereBelongsTo($message)->sole();

    expect(app(ExecuteAgentTask::class)->handle($task))->toBeNull()
        ->and($task->fresh()->status)->toBe(AgentTaskStatus::Pending)
        ->and($task->fresh()->attempts)->toBe(0);

    Ai::assertAgentNeverPrompted(AnonymousAgent::class);
});
