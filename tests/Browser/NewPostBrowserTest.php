<?php

use App\Enums\PostStatus;
use App\Models\Topic;
use App\Models\User;
use App\Models\Workspace;

test('dashboard new post button opens canonical create URL and sends the form', function () {
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
        ->click('@dashboard-new-post-button-desktop')
        ->assertPathIs('/posts/new')
        ->assertQueryStringHas('topic', $topic->slug)
        ->assertSee('New post')
        ->type('@new-post-title', 'TEST')
        ->press('@new-post-send')
        ->wait(0.5)
        ->assertDontSee('recipient field is required')
        ->assertNoJavaScriptErrors();

    $post = $topic->posts()->where('title', 'TEST')->first();

    expect($post)->not->toBeNull()
        ->and($post->sender_principal_id)->toBe($senderPrincipal->id)
        ->and($post->recipient_principal_id)->toBeNull()
        ->and($post->status)->toBe(PostStatus::Published);
});

test('new post query values become form defaults and form fields submit the post', function () {
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

    visit(route('posts.create', ['topic' => $design->slug], false))
        ->assertPathIs('/posts/new')
        ->assertQueryStringHas('topic', $design->slug)
        ->assertSelected('@new-post-topic', $design->id)
        ->type('@new-post-title', 'Query default overridden')
        ->select('@new-post-topic', $engineering->id)
        ->press('@new-post-send')
        ->wait(0.5)
        ->assertDontSee('recipient field is required')
        ->assertNoJavaScriptErrors();

    expect($design->posts()->where('title', 'Query default overridden')->exists())->toBeFalse();

    $post = $engineering->posts()->where('title', 'Query default overridden')->first();

    expect($post)->not->toBeNull()
        ->and($post->status)->toBe(PostStatus::Published);
});
