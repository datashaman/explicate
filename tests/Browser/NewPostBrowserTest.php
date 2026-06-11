<?php

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Models\Workspace;

test('dashboard new post URL opens the create panel and sends the form', function () {
    $user = User::factory()->create(['coach_marks_seen_at' => now()]);
    $workspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'name' => 'Design',
        'slug' => 'design',
    ]);

    $senderPrincipal = $workspace->principalForUser($user);

    $this->actingAs($user);

    visit(route('dashboard', ['action' => 'new-post', 'topic' => $topic->slug, 'panel' => 'posts'], false))
        ->assertPathIs('/dashboard')
        ->assertQueryStringHas('action', 'new-post')
        ->assertQueryStringHas('topic', $topic->slug)
        ->assertSee('New post')
        ->type('@new-post-body', 'TEST')
        ->press('@new-post-send')
        ->wait(0.5)
        ->assertNoJavaScriptErrors();

    $post = Post::query()
        ->where('body', 'TEST')
        ->whereHas('thread', fn ($query) => $query->whereBelongsTo($topic))
        ->first();

    expect($post)->not->toBeNull()
        ->and($post->sender_principal_id)->toBe($senderPrincipal->id)
        ->and($post->status)->toBe(PostStatus::Published);
});

test('new post query values become form defaults and form fields submit the post', function () {
    $user = User::factory()->create(['coach_marks_seen_at' => now()]);
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

    visit(route('dashboard', ['action' => 'new-post', 'topic' => $design->slug, 'panel' => 'posts'], false))
        ->assertPathIs('/dashboard')
        ->assertQueryStringHas('action', 'new-post')
        ->assertQueryStringHas('topic', $design->slug)
        ->assertSelected('@new-post-topic', $design->id)
        ->type('@new-post-body', 'Query default overridden')
        ->select('@new-post-topic', $engineering->id)
        ->press('@new-post-send')
        ->wait(0.5)
        ->assertNoJavaScriptErrors();

    expect(Post::query()
        ->where('body', 'Query default overridden')
        ->whereHas('thread', fn ($query) => $query->whereBelongsTo($design))
        ->exists())->toBeFalse();

    $post = Post::query()
        ->where('body', 'Query default overridden')
        ->whereHas('thread', fn ($query) => $query->whereBelongsTo($engineering))
        ->first();

    expect($post)->not->toBeNull()
        ->and($post->status)->toBe(PostStatus::Published);
});
