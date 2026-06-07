<?php

use App\Models\Agent;
use App\Models\Post;
use App\Models\Team;
use App\Models\Topic;
use App\Models\User;
use App\Models\Workspace;

test('database seeder creates demo workspace content', function () {
    $this->seed();

    $user = User::where('email', 'test@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->currentWorkspace)->toBeInstanceOf(Workspace::class);
    expect($user->currentWorkspace->name)->toBe('My First Workspace');
    expect($user->currentWorkspace->slug)->toBe('my-first-workspace');
    expect($user->currentWorkspace->topics)->toHaveCount(4);
    expect($user->currentWorkspace->agents)->toHaveCount(4);

    $senderPrincipal = $user->currentWorkspace->principalForUser($user);
    $workspacePosts = Post::query()
        ->whereHas('topic', fn ($query) => $query->whereBelongsTo($user->currentWorkspace))
        ->get();

    expect($workspacePosts)->not->toBeEmpty();
    expect($workspacePosts->pluck('sender_principal_id')->unique()->values()->all())
        ->toBe([$senderPrincipal->id]);

    $designTopic = $user->currentWorkspace->topics()->where('name', 'Design')->first();
    $engineeringTopic = $user->currentWorkspace->topics()->where('name', 'Engineering')->first();

    expect($designTopic)->not->toBeNull();
    expect($designTopic->slug)->toBe('design');
    expect($designTopic->posts()->count())->toBeGreaterThanOrEqual(2);
    expect($designTopic->posts()->whereNotNull('ulid')->count())->toBe($designTopic->posts()->count());
    expect($designTopic->agents()->count())->toBeGreaterThanOrEqual(2);

    expect($engineeringTopic)->not->toBeNull();
    expect($engineeringTopic->slug)->toBe('engineering');
    expect($engineeringTopic->posts()->count())->toBeGreaterThanOrEqual(2);
    expect($engineeringTopic->posts()->whereNotNull('ulid')->count())->toBe($engineeringTopic->posts()->count());

    $writerAgent = $user->currentWorkspace->agents()->where('name', 'Writer')->first();

    expect($writerAgent)->not->toBeNull();
    expect($writerAgent->slug)->toBe('writer');
    expect($writerAgent->versions)->toHaveCount(1);
    expect($writerAgent->latestVersion)->not->toBeNull();
});

test('factories derive slugs from overridden names and post ulids', function () {
    $team = Team::factory()->create(['name' => 'Demo Team']);
    $workspace = Workspace::factory()->create(['name' => 'Context Proof']);
    $topic = Topic::factory()->for($workspace)->create(['name' => 'Product Strategy']);
    $agent = Agent::factory()->for($workspace)->create(['name' => 'SEO Analyst']);
    $post = Post::factory()->for($topic)->create(['body' => 'Launch Plan']);

    expect($team->slug)->toBe('demo-team')
        ->and($workspace->slug)->toBe('context-proof')
        ->and($topic->slug)->toBe('product-strategy')
        ->and($agent->slug)->toBe('seo-analyst')
        ->and($post->ulid)->not->toBeNull();
});
