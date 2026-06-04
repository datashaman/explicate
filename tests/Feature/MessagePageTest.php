<?php

use App\Enums\MessageStatus;
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
        ->assertSee($this->message->title);
});

test('message page is forbidden for wrong workspace', function () {
    $other = Workspace::factory()->for($this->user->currentTeam)->create();
    $otherTopic = Topic::factory()->for($other)->create();
    $otherMessage = Message::factory()->for($otherTopic)->create();

    $this->actingAs($this->user)
        ->get(route('messages.show', ['topic' => $otherTopic->slug, 'message' => $otherMessage->slug]))
        ->assertForbidden();
});

test('draft message can be saved', function () {
    $this->actingAs($this->user);

    Livewire::test('pages::message', ['topic' => $this->topic, 'message' => $this->message])
        ->set('body', 'Hello world')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->message->fresh()->body)->toBe('Hello world');
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
