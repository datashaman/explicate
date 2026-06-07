<?php

use App\Enums\AgentTaskStatus;
use App\Enums\PostStatus;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\Post;
use App\Models\Topic;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    [$this->user, $this->workspace] = userWithWorkspace();
    $this->topic = Topic::factory()->for($this->workspace)->create();
    $this->senderPrincipal = $this->workspace->principalForUser($this->user);
});

test('assigning a published post to an agent creates agent work instead of a notification', function () {
    Notification::fake();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);

    $post = Post::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'status' => PostStatus::Published,
    ]);
    $this->topic->agents()->attach($agent);
    $post->assignAgents([$agent->id]);

    Notification::assertNothingSent();

    $task = AgentTask::query()
        ->whereBelongsTo($agent)
        ->whereBelongsTo($post)
        ->first();

    expect($task)->not->toBeNull()
        ->and($task->event_type)->toBe(AgentTask::EventPostAssigned)
        ->and($task->status)->toBe(AgentTaskStatus::Pending)
        ->and($task->available_at)->not->toBeNull();
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

test('publishing an assigned draft makes agent work available once', function () {
    Notification::fake();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);

    $post = Post::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'status' => PostStatus::Draft,
    ]);
    $this->topic->agents()->attach($agent);
    $post->assignAgents([$agent->id]);

    expect($post->agentTasks()->sole()->available_at)->toBeNull();

    $post->update(['status' => PostStatus::Published]);
    $post->update(['body' => 'Already sent']);

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

test('assigning multiple agents creates one task for each agent', function () {
    $agents = Agent::factory()->count(2)->for($this->workspace)->create();
    $post = Post::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'status' => PostStatus::Published,
    ]);

    $this->topic->agents()->attach($agents->pluck('id'));
    $post->assignAgents($agents->pluck('id'));

    expect($post->agentTasks)->toHaveCount(2)
        ->and($post->agentTasks->pluck('event_type')->unique()->values()->all())
        ->toBe([AgentTask::EventPostAssigned]);
});

test('post assignment only creates work for agents associated with the topic', function () {
    $associatedAgent = Agent::factory()->for($this->workspace)->create();
    $unassociatedAgent = Agent::factory()->for($this->workspace)->create();
    $this->topic->agents()->attach($associatedAgent);

    $post = Post::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'status' => PostStatus::Published,
    ]);

    $post->assignAgents([$associatedAgent->id, $unassociatedAgent->id]);

    expect($post->agentTasks)->toHaveCount(1)
        ->and($post->agentTasks->first()->agent_id)->toBe($associatedAgent->id);
});
