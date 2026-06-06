<?php

use App\Enums\MessageStatus;
use App\Enums\TeamRole;
use App\Models\Agent;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Topic;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->for($this->user->currentTeam)->create();
    $this->user->switchWorkspace($this->workspace);
    $this->topic = Topic::factory()->for($this->workspace)->create();
    $this->message = Message::factory()->for($this->topic)->create();
});

test('message page loads', function () {
    $this->actingAs($this->user)
        ->get(route('messages.show', ['topic' => $this->topic->slug, 'message' => $this->message->slug]))
        ->assertOk()
        ->assertSee($this->message->title)
        ->assertSee('data-test="message-panel"', escape: false)
        ->assertSee('min-h-[calc(100dvh-4rem)]', escape: false)
        ->assertDontSee('data-flux-breadcrumbs', escape: false);
});

test('message create page loads', function () {
    $this->actingAs($this->user)
        ->get(route('messages.create'))
        ->assertOk()
        ->assertSee('New message');
});

test('message create page preselects topic from query string', function () {
    $this->actingAs($this->user)
        ->get(route('messages.create', ['topic' => $this->topic->slug]))
        ->assertOk()
        ->assertSee($this->topic->name);
});

test('message page does not resolve topics outside the current workspace', function () {
    $other = Workspace::factory()->for($this->user->currentTeam)->create();
    $otherTopic = Topic::factory()->for($other)->create();
    $otherMessage = Message::factory()->for($otherTopic)->create();

    $this->actingAs($this->user)
        ->get(route('messages.show', ['topic' => $otherTopic->slug, 'message' => $otherMessage->slug]))
        ->assertNotFound();
});

test('draft message can be saved', function () {
    $this->actingAs($this->user);

    Livewire::test('pages::message', ['topic' => $this->topic, 'message' => $this->message])
        ->set('body', 'Hello world')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->message->fresh()->body)->toBe('Hello world');
});

test('draft message recipient can be changed to an agent principal', function () {
    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    $agentPrincipal = $this->workspace->principalForAgent($agent);

    $this->actingAs($this->user);

    Livewire::test('pages::message', ['topic' => $this->topic, 'message' => $this->message])
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
        ->get(route('messages.show', ['topic' => $this->topic->slug, 'message' => $this->message->slug]))
        ->assertOk()
        ->assertSee('From')
        ->assertSee($this->user->name)
        ->assertSee('To')
        ->assertSee('Researcher');
});

test('published message cannot be saved', function () {
    $this->message->update(['status' => MessageStatus::Published]);

    $this->actingAs($this->user);

    Livewire::test('pages::message', ['topic' => $this->topic, 'message' => $this->message])
        ->set('body', 'Hello world')
        ->call('save')
        ->assertForbidden();
});

test('attachments can be uploaded', function () {
    Storage::fake('public');

    $this->actingAs($this->user);

    $file = UploadedFile::fake()->create('report.pdf', 512, 'application/pdf');

    Livewire::test('pages::message', ['topic' => $this->topic, 'message' => $this->message])
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
    $recipient = User::factory()->create();
    $this->user->currentTeam->memberships()->create(['user_id' => $recipient->id, 'role' => TeamRole::Member]);
    $recipientPrincipal = $this->workspace->principalForUser($recipient);

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
