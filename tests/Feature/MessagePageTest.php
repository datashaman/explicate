<?php

use App\Enums\MessageStatus;
use App\Models\Agent;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Topic;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
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

test('draft message recipient cannot be changed to an agent principal', function () {
    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    $agentPrincipal = $this->workspace->principalForAgent($agent);

    $this->actingAs($this->user);

    Livewire::test('pages::message', ['message' => $this->message])
        ->set('title', 'Agent draft')
        ->set('target', 'principal')
        ->set('recipientPrincipalId', $agentPrincipal->id)
        ->call('save')
        ->assertHasErrors(['recipientPrincipalId']);

    expect($this->message->fresh())
        ->title->not->toBe('Agent draft')
        ->recipient_principal_id->toBeNull();
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
    $updatedAt = now()->subMinutes(5);

    $this->message->timestamps = false;
    $this->message->forceFill([
        'status' => MessageStatus::Published,
        'sender_principal_id' => $senderPrincipal->id,
        'updated_at' => $updatedAt,
    ])->save();

    expect($this->message->fresh()->load('sender.user')->listMeta(
        showSender: true,
        showRecipient: true,
        recipientFallback: $this->topic->name,
    ))->toBe([
        ['label' => 'From', 'value' => $this->user->name],
        ['label' => 'To', 'value' => $this->topic->name],
        ['label' => 'Sent', 'value' => '5 minutes ago', 'title' => $updatedAt->timezone(config('app.timezone'))->isoFormat('LLLL')],
    ]);
});

test('message list timestamp titles use the user timezone when provided', function () {
    $updatedAt = now()->setTimezone('UTC')->setTime(12, 0);

    Date::setTestNow($updatedAt);

    try {
        $this->message->timestamps = false;
        $this->message->forceFill([
            'status' => MessageStatus::Published,
            'updated_at' => $updatedAt,
        ])->save();

        expect($this->message->fresh()->listMeta(
            showSender: false,
            showRecipient: false,
            timezone: 'Africa/Johannesburg',
        ))->toBe([
            [
                'label' => 'Sent',
                'value' => '0 seconds ago',
                'title' => $updatedAt->copy()->timezone('Africa/Johannesburg')->isoFormat('LLLL'),
            ],
        ]);
    } finally {
        Date::setTestNow();
    }
});

test('message list sort values are normalized for deterministic column sorting', function () {
    $senderPrincipal = $this->workspace->principalForUser($this->user);
    Attachment::factory()->count(2)->for($this->message)->create();

    $this->message->timestamps = false;
    $this->message->forceFill([
        'title' => 'Mixed Case Title',
        'status' => MessageStatus::Draft,
        'sender_principal_id' => $senderPrincipal->id,
        'updated_at' => now()->setTimestamp(123),
    ])->save();

    expect($this->message->fresh()->loadCount('attachments')->load('sender.user')->listSortValues('Topic fallback'))->toMatchArray([
        'name' => 'mixed case title',
        'from' => str($this->user->name)->lower()->toString(),
        'to' => 'topic fallback',
        'saved' => '00000000000000000123',
        'attachments' => '0000000002',
        'status' => 'draft',
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
