<?php

use App\Actions\Agents\ExecuteAgentTask;
use App\Enums\AgentTaskStatus;
use App\Enums\PostStatus;
use App\Enums\Provider;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\AgentVersion;
use App\Models\Post;
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

    $post = Post::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => 'Find the latest internal context.',
        'status' => PostStatus::Published,
    ]);
    $this->topic->agents()->attach($agent);
    $post->assignAgents([$agent->id]);

    $task = AgentTask::query()->whereBelongsTo($post)->sole();

    $reply = app(ExecuteAgentTask::class)->handle($task);

    Ai::assertAgentWasPrompted(AnonymousAgent::class, function (AgentPrompt $prompt): bool {
        return $prompt->prompt === 'Find the latest internal context.'
            && $prompt->model === 'gemini-2.5-flash'
            && $prompt->provider()->name() === 'gemini'
            && $prompt->agent->instructions() === 'Answer as a concise researcher.';
    });

    expect($reply)->not->toBeNull()
        ->and($reply->body)->toBe('The agent response.')
        ->and($reply->status)->toBe(PostStatus::Published)
        ->and($reply->sender_principal_id)->toBe($this->workspace->principalForAgent($agent)->id)
        ->and($task->fresh()->status)->toBe(AgentTaskStatus::Completed)
        ->and($task->fresh()->attempts)->toBe(1)
        ->and($task->fresh()->locked_at)->toBeNull()
        ->and($task->fresh()->last_error)->toBeNull();
});

test('it marks a task failed when the agent has no executable version', function () {
    Ai::fakeAgent(AnonymousAgent::class)->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    $post = Post::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'status' => PostStatus::Published,
    ]);
    $this->topic->agents()->attach($agent);
    $post->assignAgents([$agent->id]);

    $task = AgentTask::query()->whereBelongsTo($post)->sole();
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

test('it ignores assigned draft post tasks until they are available', function () {
    Ai::fakeAgent(AnonymousAgent::class)->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    AgentVersion::factory()->for($agent)->create();
    $post = Post::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'status' => PostStatus::Draft,
    ]);
    $this->topic->agents()->attach($agent);
    $post->assignAgents([$agent->id]);

    $task = AgentTask::query()->whereBelongsTo($post)->sole();

    expect(app(ExecuteAgentTask::class)->handle($task))->toBeNull()
        ->and($task->fresh()->status)->toBe(AgentTaskStatus::Pending)
        ->and($task->fresh()->attempts)->toBe(0);

    Ai::assertAgentNeverPrompted(AnonymousAgent::class);
});
