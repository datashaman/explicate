<?php

use App\Models\Agent;
use App\Models\Attachment;
use App\Models\Post;
use App\Models\Team;
use App\Models\Thread;
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
    $workspacePosts = Post::withTrashed()
        ->whereHas('thread', fn ($query) => $query->whereBelongsTo($user->currentWorkspace))
        ->get();

    expect($workspacePosts)->not->toBeEmpty();
    expect($workspacePosts->pluck('sender_principal_id'))->toContain($senderPrincipal->id);

    $designTopic = $user->currentWorkspace->topics()->where('name', 'Design')->first();
    $engineeringTopic = $user->currentWorkspace->topics()->where('name', 'Engineering')->first();

    expect($designTopic)->not->toBeNull();
    expect($designTopic->slug)->toBe('design');
    expect($designTopic->threads()->count())->toBeGreaterThanOrEqual(2);
    expect(Post::query()->whereHas('thread', fn ($query) => $query->whereBelongsTo($designTopic))->whereNotNull('ulid')->count())
        ->toBe(Post::query()->whereHas('thread', fn ($query) => $query->whereBelongsTo($designTopic))->count());

    expect($engineeringTopic)->not->toBeNull();
    expect($engineeringTopic->slug)->toBe('engineering');
    expect(Post::withTrashed()->whereHas('thread', fn ($query) => $query->whereBelongsTo($engineeringTopic))->count())->toBeGreaterThanOrEqual(2);
    expect(Post::withTrashed()->whereHas('thread', fn ($query) => $query->whereBelongsTo($engineeringTopic))->whereNotNull('ulid')->count())
        ->toBe(Post::withTrashed()->whereHas('thread', fn ($query) => $query->whereBelongsTo($engineeringTopic))->count());
    expect(Post::onlyTrashed()->whereHas('thread', fn ($query) => $query->whereBelongsTo($engineeringTopic))->where('deleted_by_user_id', $user->id)->count())->toBe(1);

    $writerAgent = $user->currentWorkspace->agents()->where('name', 'Writer')->first();

    expect($writerAgent)->not->toBeNull();
    expect($writerAgent->slug)->toBe('writer');
    expect($writerAgent->versions)->toHaveCount(1);
    expect($writerAgent->latestVersion)->not->toBeNull();

    $attachments = Attachment::query()
        ->whereHas('post.thread', fn ($query) => $query->whereBelongsTo($user->currentWorkspace))
        ->get();

    expect($attachments)->toHaveCount(3);
    expect($attachments->pluck('mime_type')->contains('image/svg+xml'))->toBeTrue();

    $workspace = $user->currentWorkspace;
    $attachments->each(function (Attachment $attachment) use ($workspace): void {
        expect($workspace->filesystem()->exists($attachment->path))->toBeTrue();
    });
});

test('factories derive slugs from overridden names and post ulids', function () {
    $team = Team::factory()->create(['name' => 'Demo Team']);
    $workspace = Workspace::factory()->create(['name' => 'Context Proof']);
    $topic = Topic::factory()->for($workspace)->create(['name' => 'Product Strategy']);
    $agent = Agent::factory()->for($workspace)->create(['name' => 'SEO Analyst']);
    $post = Post::factory()->for(Thread::factory()->forTopic($topic))->create(['body' => 'Launch Plan']);

    expect($team->slug)->toBe('demo-team')
        ->and($workspace->slug)->toBe('context-proof')
        ->and($topic->slug)->toBe('product-strategy')
        ->and($agent->slug)->toBe('seo-analyst')
        ->and($post->ulid)->not->toBeNull();
});
