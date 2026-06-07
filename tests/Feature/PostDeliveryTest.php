<?php

use App\Enums\AgentTaskStatus;
use App\Enums\PostStatus;
use App\Jobs\ProcessAgentTask;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\Post;
use App\Models\Topic;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    [$this->user, $this->workspace] = userWithWorkspace();
    $this->topic = Topic::factory()->for($this->workspace)->create();
    $this->senderPrincipal = $this->workspace->principalForUser($this->user);

    Queue::fake();
});

test('mentioning an agent in a published post creates agent work instead of a notification', function () {
    Notification::fake();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);

    $post = Post::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => '@researcher Find the latest internal context.',
        'status' => PostStatus::Published,
    ]);

    Notification::assertNothingSent();

    $task = AgentTask::query()
        ->whereBelongsTo($agent)
        ->whereBelongsTo($post)
        ->first();

    expect($task)->not->toBeNull()
        ->and($task->event_type)->toBe(AgentTask::EventPostMentioned)
        ->and($task->status)->toBe(AgentTaskStatus::Pending)
        ->and($task->available_at)->not->toBeNull();

    Queue::assertPushed(ProcessAgentTask::class, fn (ProcessAgentTask $job): bool => $job->task->is($task));
});

test('topic posts without assignments do not create notifications or agent tasks', function () {
    Notification::fake();

    Post::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'status' => PostStatus::Published,
    ]);

    Notification::assertNothingSent();

    expect(AgentTask::query()->count())->toBe(0);
});

test('publishing a draft with an agent mention creates agent work once', function () {
    Notification::fake();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);

    $post = Post::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => '@researcher Draft context.',
        'status' => PostStatus::Draft,
    ]);

    expect($post->agentTasks()->count())->toBe(0);

    $post->update(['status' => PostStatus::Published]);
    $post->update(['body' => '@researcher Already sent']);

    $task = AgentTask::query()
        ->whereBelongsTo($agent)
        ->whereBelongsTo($post)
        ->sole();

    expect($task->available_at)->not->toBeNull()
        ->and(AgentTask::query()
            ->whereBelongsTo($agent)
            ->whereBelongsTo($post)
            ->count())->toBe(1);
});

test('mentioning multiple agents creates one task for each agent', function () {
    Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    Agent::factory()->for($this->workspace)->create(['name' => 'Reviewer']);

    $post = Post::factory()->for($this->topic)->create([
        'body' => '@researcher @reviewer Please collaborate.',
        'sender_principal_id' => $this->senderPrincipal->id,
        'status' => PostStatus::Published,
    ]);

    expect($post->agentTasks)->toHaveCount(2)
        ->and($post->agentTasks->pluck('event_type')->unique()->values()->all())
        ->toBe([AgentTask::EventPostMentioned]);
});

test('mentions only create work for agents in the post workspace', function () {
    $mentionedAgent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    Agent::factory()->create(['name' => 'Reviewer']);

    $post = Post::factory()->for($this->topic)->create([
        'body' => '@researcher @reviewer Please review.',
        'sender_principal_id' => $this->senderPrincipal->id,
        'status' => PostStatus::Published,
    ]);

    expect($post->agentTasks)->toHaveCount(1)
        ->and($post->agentTasks->first()->agent_id)->toBe($mentionedAgent->id);
});
