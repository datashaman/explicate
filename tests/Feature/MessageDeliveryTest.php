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

test('sending a direct message to an agent creates agent work instead of a notification', function () {
    Notification::fake();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    $agentPrincipal = $this->workspace->principalForAgent($agent);

    $message = Message::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'recipient_principal_id' => $agentPrincipal->id,
        'status' => MessageStatus::Published,
    ]);

    Notification::assertNothingSent();

    $task = AgentTask::query()
        ->whereBelongsTo($agent)
        ->whereBelongsTo($message)
        ->first();

    expect($task)->not->toBeNull()
        ->and($task->event_type)->toBe('message_received')
        ->and($task->status)->toBe(AgentTaskStatus::Pending)
        ->and($task->available_at)->not->toBeNull();
});

test('topic messages do not create direct recipient notifications or agent tasks', function () {
    Notification::fake();

    Message::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'recipient_principal_id' => null,
        'status' => MessageStatus::Published,
    ]);

    Notification::assertNothingSent();

    expect(AgentTask::query()->count())->toBe(0);
});

test('publishing a draft dispatches delivery side effects once', function () {
    Notification::fake();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    $agentPrincipal = $this->workspace->principalForAgent($agent);

    $message = Message::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'recipient_principal_id' => $agentPrincipal->id,
        'status' => MessageStatus::Draft,
    ]);

    $message->update(['status' => MessageStatus::Published]);
    $message->update(['title' => 'Already sent']);

    expect(AgentTask::query()
        ->whereBelongsTo($agent)
        ->whereBelongsTo($message)
        ->count())->toBe(1);
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
