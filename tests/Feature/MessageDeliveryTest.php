<?php

use App\Enums\AgentTaskStatus;
use App\Enums\MessageStatus;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\Message;
use App\Models\Topic;
use App\Notifications\MessageReceived;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    [$this->user, $this->workspace] = userWithWorkspace();
    $this->topic = Topic::factory()->for($this->workspace)->create();
    $this->senderPrincipal = $this->workspace->principalForUser($this->user);
});

test('sending a direct message to a user creates a database notification', function () {
    [$recipient, $recipientPrincipal] = teamMemberPrincipal($this->user, $this->workspace);

    $message = Message::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'recipient_principal_id' => $recipientPrincipal->id,
        'status' => MessageStatus::Published,
        'title' => 'Review request',
    ]);

    $notification = $recipient->notifications()->first();

    expect($notification)->not->toBeNull()
        ->and($notification->type)->toBe(MessageReceived::class)
        ->and($notification->data)->toMatchArray([
            'message_id' => $message->id,
            'message_ulid' => $message->ulid,
            'topic_id' => $this->topic->id,
            'topic_name' => $this->topic->name,
            'title' => 'Review request',
            'sender_name' => $this->user->name,
        ]);
});

test('assigning a published message to an agent creates agent work instead of a notification', function () {
    Notification::fake();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);

    $message = Message::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'recipient_principal_id' => null,
        'status' => MessageStatus::Published,
    ]);
    $this->topic->agents()->attach($agent);
    $message->assignAgents([$agent->id]);

    Notification::assertNothingSent();

    $task = AgentTask::query()
        ->whereBelongsTo($agent)
        ->whereBelongsTo($message)
        ->first();

    expect($task)->not->toBeNull()
        ->and($task->event_type)->toBe(AgentTask::EventMessageAssigned)
        ->and($task->status)->toBe(AgentTaskStatus::Pending)
        ->and($task->available_at)->not->toBeNull();
});

test('topic messages without assignments do not create direct recipient notifications or agent tasks', function () {
    Notification::fake();

    Message::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'recipient_principal_id' => null,
        'status' => MessageStatus::Published,
    ]);

    Notification::assertNothingSent();

    expect(AgentTask::query()->count())->toBe(0);
});

test('publishing an assigned draft makes agent work available once', function () {
    Notification::fake();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);

    $message = Message::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'recipient_principal_id' => null,
        'status' => MessageStatus::Draft,
    ]);
    $this->topic->agents()->attach($agent);
    $message->assignAgents([$agent->id]);

    expect($message->agentTasks()->sole()->available_at)->toBeNull();

    $message->update(['status' => MessageStatus::Published]);
    $message->update(['title' => 'Already sent']);

    $task = AgentTask::query()
        ->whereBelongsTo($agent)
        ->whereBelongsTo($message)
        ->sole();

    expect($task->available_at)->not->toBeNull()
        ->and(AgentTask::query()
            ->whereBelongsTo($agent)
            ->whereBelongsTo($message)
            ->count())->toBe(1);
});

test('assigning multiple agents creates one task for each agent', function () {
    $agents = Agent::factory()->count(2)->for($this->workspace)->create();
    $message = Message::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'status' => MessageStatus::Published,
    ]);

    $this->topic->agents()->attach($agents->pluck('id'));
    $message->assignAgents($agents->pluck('id'));

    expect($message->agentTasks)->toHaveCount(2)
        ->and($message->agentTasks->pluck('event_type')->unique()->values()->all())
        ->toBe([AgentTask::EventMessageAssigned]);
});

test('message assignment only creates work for agents associated with the topic', function () {
    $associatedAgent = Agent::factory()->for($this->workspace)->create();
    $unassociatedAgent = Agent::factory()->for($this->workspace)->create();
    $this->topic->agents()->attach($associatedAgent);

    $message = Message::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'status' => MessageStatus::Published,
    ]);

    $message->assignAgents([$associatedAgent->id, $unassociatedAgent->id]);

    expect($message->agentTasks)->toHaveCount(1)
        ->and($message->agentTasks->first()->agent_id)->toBe($associatedAgent->id);
});

test('sending a message to yourself does not create a human notification', function () {
    Notification::fake();

    Message::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'recipient_principal_id' => $this->senderPrincipal->id,
        'status' => MessageStatus::Published,
    ]);

    Notification::assertNothingSent();
});
