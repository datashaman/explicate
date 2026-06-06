<?php

use App\Enums\MessageStatus;
use App\Models\Topic;
use App\Models\User;
use App\Models\Workspace;

test('dashboard new message button opens canonical create URL and sends the form', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'name' => 'Design',
        'slug' => 'design',
    ]);

    $senderPrincipal = $workspace->principalForUser($user);

    $this->actingAs($user);

    visit(route('dashboard', ['topic' => $topic->slug], false))
        ->click('@dashboard-new-message-button-desktop')
        ->assertPathIs('/messages/new')
        ->assertQueryStringHas('topic', $topic->slug)
        ->assertSee('New message')
        ->type('@new-message-title', 'TEST')
        ->press('@new-message-send')
        ->wait(0.5)
        ->assertDontSee('recipient field is required')
        ->assertNoJavaScriptErrors();

    $message = $topic->messages()->where('title', 'TEST')->first();

    expect($message)->not->toBeNull()
        ->and($message->sender_principal_id)->toBe($senderPrincipal->id)
        ->and($message->recipient_principal_id)->toBeNull()
        ->and($message->status)->toBe(MessageStatus::Published);
});

test('new message query values become form defaults and form fields submit the message', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

    $design = Topic::factory()->for($workspace)->create([
        'name' => 'Design',
        'slug' => 'design',
    ]);
    $engineering = Topic::factory()->for($workspace)->create([
        'name' => 'Engineering',
        'slug' => 'engineering',
    ]);

    $this->actingAs($user);

    visit(route('messages.create', ['topic' => $design->slug], false))
        ->assertPathIs('/messages/new')
        ->assertQueryStringHas('topic', $design->slug)
        ->assertSelected('@new-message-topic', $design->id)
        ->type('@new-message-title', 'Query default overridden')
        ->select('@new-message-topic', $engineering->id)
        ->press('@new-message-send')
        ->wait(0.5)
        ->assertDontSee('recipient field is required')
        ->assertNoJavaScriptErrors();

    expect($design->messages()->where('title', 'Query default overridden')->exists())->toBeFalse();

    $message = $engineering->messages()->where('title', 'Query default overridden')->first();

    expect($message)->not->toBeNull()
        ->and($message->status)->toBe(MessageStatus::Published);
});
