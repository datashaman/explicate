<?php

use App\Enums\MessageStatus;
use App\Models\Agent;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Topic;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    [$this->user, $this->workspace] = userWithWorkspace();
    $this->topic = Topic::factory()->for($this->workspace)->create();
    $this->message = Message::factory()->for($this->topic)->create();
});

test('message page loads', function () {
    $this->actingAs($this->user)
        ->get(route('messages.show', ['message' => $this->message]))
        ->assertOk()
        ->assertSee($this->message->title)
        ->assertSee('data-test="message-panel"', escape: false)
        ->assertSee('min-h-[calc(100dvh-4rem)]', escape: false)
        ->assertDontSee('data-flux-breadcrumbs', escape: false);
});

test('message create route redirects to the dashboard message panel', function () {
    $this->actingAs($this->user)
        ->get(route('messages.create'))
        ->assertOk()
        ->assertSee('data-test="dashboard-message-create-panel"', escape: false);
});

test('message create route uses the selected topic query as the form default', function () {
    $this->actingAs($this->user)
        ->get(route('messages.create', ['topic' => $this->topic->slug]))
        ->assertOk()
        ->assertSee('data-test="dashboard-message-create-panel"', escape: false)
        ->assertSee('&quot;newMessageTopicId&quot;:'.$this->topic->id, escape: false);
});

test('message page does not resolve topics outside the current workspace', function () {
    $other = Workspace::factory()->for($this->user->currentTeam)->create();
    $otherTopic = Topic::factory()->for($other)->create();
    $otherMessage = Message::factory()->for($otherTopic)->create();

    $this->actingAs($this->user)
        ->get(route('messages.show', ['message' => $otherMessage]))
        ->assertNotFound();
});

test('draft message can be saved', function () {
    $this->actingAs($this->user);

    Livewire::test('pages::message', ['message' => $this->message])
        ->set('body', 'Hello world')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->message->fresh()->body)->toBe('Hello world');
});

test('draft message recipient can be changed to an agent principal', function () {
    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    $agentPrincipal = $this->workspace->principalForAgent($agent);

    $this->actingAs($this->user);

    Livewire::test('pages::message', ['message' => $this->message])
        ->set('title', 'Agent draft')
        ->set('target', 'principal')
        ->set('recipientPrincipalId', $agentPrincipal->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->message->fresh())
        ->title->toBe('Agent draft')
        ->recipient_principal_id->toBe($agentPrincipal->id);
});

test('published message page shows sender and recipient principals', function () {
    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    $senderPrincipal = $this->workspace->principalForUser($this->user);
    $agentPrincipal = $this->workspace->principalForAgent($agent);

    $this->message->update([
        'status' => MessageStatus::Published,
        'sender_principal_id' => $senderPrincipal->id,
        'recipient_principal_id' => $agentPrincipal->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('messages.show', ['message' => $this->message]))
        ->assertOk()
        ->assertSee('From')
        ->assertSee($this->user->name)
        ->assertSee('To')
        ->assertSee('Researcher');
});

test('message list metadata uses sender recipient fallback and timestamp labels', function () {
    $senderPrincipal = $this->workspace->principalForUser($this->user);

    $this->message->timestamps = false;
    $this->message->forceFill([
        'status' => MessageStatus::Published,
        'sender_principal_id' => $senderPrincipal->id,
        'updated_at' => now()->subMinutes(5),
    ])->save();

    expect($this->message->fresh()->load('sender.user')->listMeta(
        showSender: true,
        showRecipient: true,
        recipientFallback: $this->topic->name,
    ))->toBe([
        ['label' => 'From', 'value' => $this->user->name],
        ['label' => 'To', 'value' => $this->topic->name],
        ['label' => 'Sent', 'value' => '5 minutes ago'],
    ]);
});

test('published message cannot be saved', function () {
    $this->message->update(['status' => MessageStatus::Published]);

    $this->actingAs($this->user);

    Livewire::test('pages::message', ['message' => $this->message])
        ->set('body', 'Hello world')
        ->call('save')
        ->assertForbidden();
});

test('attachments can be uploaded', function () {
    Storage::fake('public');

    $this->actingAs($this->user);

    $file = UploadedFile::fake()->create('report.pdf', 512, 'application/pdf');

    Livewire::test('pages::message', ['message' => $this->message])
        ->set('uploads', [$file])
        ->call('uploadAttachments')
        ->assertHasNoErrors();

    expect($this->message->attachments()->count())->toBe(1);
    expect($this->message->attachments()->first()->filename)->toBe('report.pdf');
});

test('message can be created from dedicated create page with attachments', function () {
    Storage::fake('public');

    $this->actingAs($this->user);

    $file = UploadedFile::fake()->create('brief.pdf', 256, 'application/pdf');

    Livewire::test('pages::message-create')
        ->set('title', 'New draft')
        ->set('body', 'Draft body')
        ->set('topicId', $this->topic->id)
        ->set('uploads', [$file])
        ->call('create')
        ->assertHasNoErrors();

    $message = $this->topic->messages()->where('title', 'New draft')->first();

    expect($message)->not->toBeNull();
    expect($message->body)->toBe('Draft body');
    expect($message->attachments)->toHaveCount(1);
    expect($message->attachments->first()->filename)->toBe('brief.pdf');
});

test('message can be made actionable from dedicated create page', function () {
    $this->actingAs($this->user);

    Livewire::test('pages::message-create')
        ->set('title', 'Ready to send')
        ->set('body', 'Actionable body')
        ->set('topicId', $this->topic->id)
        ->call('send')
        ->assertHasNoErrors();

    $message = $this->topic->messages()->where('title', 'Ready to send')->first();
    $senderPrincipal = $this->workspace->principalForUser($this->user);

    expect($message)->not->toBeNull()
        ->and($message->body)->toBe('Actionable body')
        ->and($message->sender_principal_id)->toBe($senderPrincipal->id)
        ->and($message->status)->toBe(MessageStatus::Published);
});

test('message can be sent to a user from dedicated create page', function () {
    [$recipient, $recipientPrincipal] = teamMemberPrincipal($this->user, $this->workspace);

    $this->actingAs($this->user);

    Livewire::test('pages::message-create')
        ->set('title', 'User message')
        ->set('body', 'Direct body')
        ->set('target', 'principal')
        ->set('topicId', $this->topic->id)
        ->set('recipientPrincipalId', $recipientPrincipal->id)
        ->call('send')
        ->assertHasNoErrors();

    $message = $this->topic->messages()->where('title', 'User message')->first();
    $senderPrincipal = $this->workspace->principalForUser($this->user);

    expect($message)->not->toBeNull()
        ->and($message->body)->toBe('Direct body')
        ->and($message->sender_principal_id)->toBe($senderPrincipal->id)
        ->and($message->recipient_principal_id)->toBe($recipientPrincipal->id)
        ->and($message->status)->toBe(MessageStatus::Published);
});

test('dedicated create page defaults a principal recipient when none reached the server', function () {
    $this->actingAs($this->user);

    $senderPrincipal = $this->workspace->principalForUser($this->user);

    Livewire::test('pages::message-create')
        ->set('title', 'Default recipient')
        ->set('body', 'Direct body')
        ->set('target', 'principal')
        ->set('topicId', $this->topic->id)
        ->set('recipientPrincipalId', null)
        ->call('send')
        ->assertHasNoErrors();

    $message = $this->topic->messages()->where('title', 'Default recipient')->first();

    expect($message)->not->toBeNull()
        ->and($message->sender_principal_id)->toBe($senderPrincipal->id)
        ->and($message->recipient_principal_id)->toBe($senderPrincipal->id)
        ->and($message->status)->toBe(MessageStatus::Published);
});

test('draft message defaults a principal recipient when none reached the server', function () {
    $this->actingAs($this->user);

    $senderPrincipal = $this->workspace->principalForUser($this->user);

    Livewire::test('pages::message', ['message' => $this->message])
        ->set('title', 'Default draft recipient')
        ->set('target', 'principal')
        ->set('recipientPrincipalId', null)
        ->call('publish')
        ->assertHasNoErrors();

    expect($this->message->fresh())
        ->title->toBe('Default draft recipient')
        ->sender_principal_id->toBe($senderPrincipal->id)
        ->recipient_principal_id->toBe($senderPrincipal->id)
        ->status->toBe(MessageStatus::Published);
});

test('a topic has many messages', function () {
    Message::factory()->count(2)->for($this->topic)->create();

    expect($this->topic->messages()->count())->toBe(3); // 1 from beforeEach + 2
});

test('a message has many attachments', function () {
    Attachment::factory()->count(2)->for($this->message)->create();

    expect($this->message->attachments()->count())->toBe(2);
});

test('attachments are soft deleted', function () {
    $attachment = Attachment::factory()->for($this->message)->create();
    $attachment->delete();

    expect(Attachment::withTrashed()->find($attachment->id))->not->toBeNull();
    expect(Attachment::find($attachment->id))->toBeNull();
});
