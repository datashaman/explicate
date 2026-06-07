<?php

use App\Broadcasting\WorkspaceChannel;
use App\Enums\PostStatus;
use App\Enums\Provider;
use App\Enums\ReasoningEffort;
use App\Events\WorkspacePostsChanged;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\AgentVersion;
use App\Models\Attachment;
use App\Models\Post;
use App\Models\Thread;
use App\Models\Topic;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('a workspace can have many topics', function () {
    $workspace = Workspace::factory()->create();
    Topic::factory()->count(3)->for($workspace)->create();

    expect($workspace->topics)->toHaveCount(3);
});

test('a workspace can have zero topics', function () {
    $workspace = Workspace::factory()->create();

    expect($workspace->topics)->toHaveCount(0);
});

test('a topic belongs to a workspace', function () {
    $topic = Topic::factory()->create();

    expect($topic->workspace)->toBeInstanceOf(Workspace::class);
});

test('a topic has many threads', function () {
    $topic = Topic::factory()->create();
    Thread::factory()->count(2)->for($topic)->create();

    expect($topic->threads()->count())->toBe(2);
});

test('a thread belongs to a topic and holds posts', function () {
    $topic = Topic::factory()->create();
    $thread = Thread::factory()->for($topic)->create(['title' => 'Review artifact']);
    $post = Post::factory()->for($topic)->for($thread)->create(['body' => 'Review note']);

    expect($thread->topic)->toBeInstanceOf(Topic::class)
        ->and($thread->posts()->pluck('posts.id')->all())->toBe([$post->id])
        ->and($post->thread->is($thread))->toBeTrue();
});

test('topics are ordered by name', function () {
    $workspace = Workspace::factory()->create();
    Topic::factory()->for($workspace)->create(['name' => 'Zebra', 'slug' => 'zebra']);
    Topic::factory()->for($workspace)->create(['name' => 'Apple', 'slug' => 'apple']);

    expect($workspace->topics->first()->name)->toBe('Apple');
    expect($workspace->topics->last()->name)->toBe('Zebra');
});

test('topics are soft deleted', function () {
    $topic = Topic::factory()->create();
    $topic->delete();

    expect(Topic::withTrashed()->find($topic->id))->not->toBeNull();
    expect(Topic::find($topic->id))->toBeNull();
});

test('slug is unique per workspace', function () {
    $workspace = Workspace::factory()->create();
    Topic::factory()->for($workspace)->create(['name' => 'My Topic', 'slug' => 'my-topic']);

    expect(fn () => Topic::factory()->for($workspace)->create(['name' => 'My Topic', 'slug' => 'my-topic']))
        ->toThrow(QueryException::class);
});

test('dashboard shows topics as folders for current workspace', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design']);
    $post = Post::factory()->for($topic)->create(['body' => 'Dashboard draft']);
    $publishedPost = Post::factory()->for($topic)->create([
        'body' => 'Dashboard published',
        'status' => PostStatus::Published,
        'sender_principal_id' => $workspace->principalForUser($user)->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Topics')
        ->assertSee('Feed')
        ->assertSee('Drafts')
        ->assertSee('Archived')
        ->assertSee($topic->name)
        ->assertSee($publishedPost->body)
        ->assertDontSee($post->body)
        ->assertDontSee('Select a topic')
        ->assertDontSee('Choose a topic to view its feed.');
});

test('dashboard shows system folders with workspace post counts', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);
    $userPrincipal = $workspace->principalForUser($user);
    Post::factory()->for($topic)->create(['status' => PostStatus::Draft]);
    Post::factory()->for($topic)->create([
        'status' => PostStatus::Published,
        'sender_principal_id' => $userPrincipal->id,
    ]);
    Post::factory()->for($topic)->create([
        'status' => PostStatus::Published,
        'sender_principal_id' => $userPrincipal->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(e(route('dashboard')), escape: false)
        ->assertSee(e(route('posts.drafts')), escape: false)
        ->assertSee(e(route('posts.archived')), escape: false)
        ->assertSee('data-test="system-folder-feed-count"', escape: false)
        ->assertSee('data-test="system-folder-drafts-count"', escape: false);
});

test('post changes broadcast to the post workspace', function () {
    Event::fake([WorkspacePostsChanged::class]);

    [$user, $workspace] = userWithWorkspace();
    $topic = Topic::factory()->for($workspace)->create();

    $post = Post::factory()->for($topic)->create([
        'status' => PostStatus::Published,
        'sender_principal_id' => $workspace->principalForUser($user)->id,
    ]);
    $post->update(['body' => 'Updated over websockets.']);
    $post->delete();

    Event::assertDispatched(
        WorkspacePostsChanged::class,
        fn (WorkspacePostsChanged $event): bool => $event->workspaceId === $workspace->id
            && $event->postId === $post->id,
    );
    Event::assertDispatchedTimes(WorkspacePostsChanged::class, 3);
});

test('workspace post broadcast channels are scoped to team members', function () {
    [$user, $workspace] = userWithWorkspace();
    [$otherUser] = userWithWorkspace();

    $channel = app(WorkspaceChannel::class);

    expect($channel->join($user, $workspace->id))->toBeTrue()
        ->and($channel->join($otherUser, $workspace->id))->toBeFalse()
        ->and($channel->join($user, 999_999))->toBeFalse();
});

test('dashboard listens for workspace post broadcasts', function () {
    [$user, $workspace] = userWithWorkspace();

    $this->actingAs($user);

    $component = Livewire::test('pages::dashboard');

    expect($component->instance()->getListeners())->toBe([
        "echo-private:workspaces.{$workspace->id},.posts.changed" => 'refreshWorkspacePosts',
    ]);
});

test('dashboard system draft folder shows draft posts across topics', function () {
    [$user, $workspace] = userWithWorkspace();

    $design = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);
    $engineering = Topic::factory()->for($workspace)->create(['slug' => 'engineering']);
    $userPrincipal = $workspace->principalForUser($user);

    $designDraft = Post::factory()->for($design)->create([
        'body' => 'Working draft',
        'updated_at' => now()->subMinutes(7),
        'status' => PostStatus::Draft,
    ]);

    Post::factory()->for($engineering)->create([
        'body' => 'Engineering sent',
        'status' => PostStatus::Published,
        'sender_principal_id' => $userPrincipal->id,
    ]);

    $response = $this->actingAs($user)
        ->get(route('posts.drafts'))
        ->assertOk()
        ->assertSee('data-test="folder-title"', escape: false)
        ->assertSee('Drafts')
        ->assertSee('Working draft')
        ->assertSee(e(route('posts.drafts', [
            'topic' => $design->slug,
            'post' => $designDraft->ulid,
            'panel' => 'posts',
        ])), escape: false)
        ->assertSee('data-test="post-message"', escape: false)
        ->assertSee('data-test="post-message-topic"', escape: false)
        ->assertSee(e(route('dashboard', ['topic' => $design->slug, 'panel' => 'posts'])), escape: false)
        ->assertSee('#Design')
        ->assertSee('data-test="post-message-timestamp"', escape: false)
        ->assertDontSee('data-test="folder-list-sort-from"', escape: false)
        ->assertDontSee('data-test="folder-list-sort-sender"', escape: false)
        ->assertDontSee('data-test="folder-list-sort-header"', escape: false)
        ->assertDontSeeText('Author')
        ->assertSee('data-sort-topic=', escape: false)
        ->assertSeeText('7 minutes ago')
        ->assertSee('data-sort-saved=', escape: false)
        ->assertDontSee('data-test="folder-list-sort-sent"', escape: false)
        ->assertDontSee('Engineering sent');

    $response->assertDontSee('data-test="folder-item-badge"', escape: false);
});

test('dashboard feed folder does not show draft posts', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);
    $userPrincipal = $workspace->principalForUser($user);

    Post::factory()->for($topic)->create([
        'body' => 'Hidden draft',
        'status' => PostStatus::Draft,
    ]);

    Post::factory()->for($topic)->create([
        'body' => 'Visible post',
        'status' => PostStatus::Published,
        'sender_principal_id' => $userPrincipal->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Visible post')
        ->assertDontSee('Hidden draft');
});

test('dashboard feed folder shows all published topic posts', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);
    $userPrincipal = $workspace->principalForUser($user);

    $firstPost = Post::factory()->for($topic)->create([
        'body' => 'First topic post',
        'status' => PostStatus::Published,
        'sender_principal_id' => $userPrincipal->id,
    ]);
    $firstPost->timestamps = false;
    $firstPost->forceFill(['created_at' => now()->subMinutes(9)])->save();

    Post::factory()->for($topic)->create([
        'body' => 'Second topic post',
        'status' => PostStatus::Published,
        'sender_principal_id' => $userPrincipal->id,
    ]);

    Post::factory()->for($topic)->create([
        'body' => 'Third topic post',
        'status' => PostStatus::Published,
        'sender_principal_id' => $userPrincipal->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-test="folder-title"', escape: false)
        ->assertSeeText('Feed')
        ->assertSee('First topic post')
        ->assertSee('Second topic post')
        ->assertSee('Third topic post')
        ->assertDontSeeText('Author')
        ->assertSee('data-test="post-message"', escape: false)
        ->assertSee('data-test="post-message-actions"', escape: false)
        ->assertDontSee('data-test="folder-list-sort-header"', escape: false)
        ->assertSeeText($user->name)
        ->assertSee('data-test="post-message-topic"', escape: false)
        ->assertSee(e(route('dashboard', ['topic' => $topic->slug, 'panel' => 'posts'])), escape: false)
        ->assertSee('#Design')
        ->assertSeeText('9 minutes ago');
});

test('dashboard post panel returns to the selected folder before the post topic', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);
    $post = Post::factory()->for($topic)->create([
        'body' => 'Feed post',
        'status' => PostStatus::Published,
        'sender_principal_id' => $workspace->principalForUser($user)->id,
    ]);

    $this->actingAs($user);

    $component = Livewire::test('pages::dashboard');
    $component->instance()->selectedSystemFolderSlug = 'feed';
    $component->instance()->selectedTopicSlug = $topic->slug;
    $component->instance()->selectedPostUlid = $post->ulid;

    expect($component->instance()->postsPanelReturnRoute())
        ->toBe(route('dashboard'))
        ->and($component->instance()->postsPanelReturnLabel())
        ->toBe('Feed');
});

test('dashboard post panel return label matches selected topic context', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);
    $post = Post::factory()->for($topic)->create([
        'body' => 'Design draft',
        'status' => PostStatus::Draft,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug, 'post' => $post->ulid, 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('id="posts-panel"', escape: false)
        ->assertSee('id="thread-panel"', escape: false)
        ->assertSee('data-test="thread-panel-close"', escape: false)
        ->assertSeeText('Design');
});

test('dashboard archived folder shows archived feed', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);
    $userPrincipal = $workspace->principalForUser($user);

    $archivedPost = Post::factory()->for($topic)->create([
        'body' => 'Archived post',
        'status' => PostStatus::Archived,
        'sender_principal_id' => $userPrincipal->id,
    ]);
    $archivedPost->timestamps = false;
    $archivedPost->forceFill(['created_at' => now()->subMinutes(11)])->save();

    $response = $this->actingAs($user)
        ->get(route('posts.archived'))
        ->assertOk()
        ->assertSee('data-test="folder-title"', escape: false)
        ->assertSeeText('Archived')
        ->assertSee('Archived post')
        ->assertDontSeeText('Author')
        ->assertSee('data-test="post-message"', escape: false)
        ->assertSee('data-test="post-message-actions"', escape: false)
        ->assertDontSee('data-test="folder-list-sort-header"', escape: false)
        ->assertSeeText($user->name)
        ->assertSee('#Design')
        ->assertSeeText('11 minutes ago');

    $response->assertDontSee('data-test="folder-item-badge"', escape: false);
});

test('dashboard archived toggle only filters the selected posts list', function () {
    [$user, $workspace] = userWithWorkspace();

    $design = Topic::factory()->for($workspace)->create([
        'name' => 'Design',
        'slug' => 'design',
    ]);

    $engineering = Topic::factory()->for($workspace)->create([
        'name' => 'Engineering',
        'slug' => 'engineering',
    ]);

    Post::factory()->for($design)->create([
        'body' => 'Design draft',
        'status' => PostStatus::Draft,
    ]);

    Post::factory()->for($design)->create([
        'body' => 'Design published',
        'status' => PostStatus::Published,
    ]);

    Post::factory()->for($design)->create([
        'body' => 'Design archived',
        'status' => PostStatus::Archived,
    ]);

    Post::factory()->count(9)->for($engineering)->create([
        'status' => PostStatus::Archived,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $design->slug]))
        ->assertOk()
        ->assertDontSee('Design draft')
        ->assertDontSee('data-test="topic-design-draft-count"', escape: false)
        ->assertDontSee('title="Draft posts"', escape: false)
        ->assertSee('data-test="topic-design-published-count"', escape: false)
        ->assertSee('title="Feed"', escape: false)
        ->assertDontSee('Design archived')
        ->assertDontSee('data-test="topic-design-archived-count"', escape: false)
        ->assertDontSee('data-test="topic-engineering-archived-count"', escape: false);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->set('selectedTopicSlug', $design->slug)
        ->set('showArchived', true)
        ->assertSee('Design archived')
        ->assertSee('data-test="topic-design-archived-count"', escape: false)
        ->assertSee('title="Archived posts"', escape: false)
        ->assertSee('data-count="1"', escape: false)
        ->assertDontSee('data-test="topic-engineering-archived-count"', escape: false);
});

test('dashboard routes do not include team or workspace slugs', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'current-topic']);

    expect(route('dashboard', absolute: false))->toBe('/dashboard')
        ->and(route('topics.show', ['topic' => $topic->slug], false))->toBe('/topics/current-topic')
        ->and(route('posts.show', ['post' => 'current-post'], false))->toBe('/posts/current-post')
        ->and(route('posts.create', ['topic' => $topic->slug], false))->toBe('/posts/new?topic=current-topic');
});

test('topic routes resolve slugs inside the current workspace', function () {
    $user = User::factory()->create();
    $currentWorkspace = Workspace::factory()->for($user->currentTeam)->create();
    $otherWorkspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($currentWorkspace);

    Topic::factory()->for($otherWorkspace)->create([
        'name' => 'Other Topic',
        'slug' => 'shared-topic',
    ]);

    $currentTopic = Topic::factory()->for($currentWorkspace)->create([
        'name' => 'Current Topic',
        'slug' => 'shared-topic',
    ]);

    $this->actingAs($user)
        ->get(route('topics.show', ['topic' => $currentTopic->slug]))
        ->assertOk()
        ->assertSee('Current Topic')
        ->assertDontSee('Other Topic');
});

test('dashboard shows selected topic in the main panel', function () {
    [$user, $workspace] = userWithWorkspace();

    $selectedTopic = Topic::factory()->for($workspace)->create(['name' => 'Selected Topic', 'slug' => 'selected-topic']);
    $otherTopic = Topic::factory()->for($workspace)->create(['name' => 'Other Topic', 'slug' => 'other-topic']);

    $selectedPost = Post::factory()->for($selectedTopic)->create([
        'body' => 'Selected post',
        'status' => PostStatus::Published,
    ]);
    Post::factory()->for($otherTopic)->create(['body' => 'Other post']);
    Post::factory()->for($selectedTopic)->create([
        'body' => 'Another selected post',
        'status' => PostStatus::Published,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $selectedTopic->slug]))
        ->assertOk()
        ->assertSee('Selected Topic')
        ->assertSee($selectedPost->body)
        ->assertSee(e(route('dashboard', ['topic' => $selectedTopic->slug, 'post' => $selectedPost->ulid, 'panel' => 'posts'])), escape: false)
        ->assertDontSee(route('posts.show', ['post' => $selectedPost]), escape: false)
        ->assertDontSee('data-test="folder-list-sort-topic"', escape: false)
        ->assertDontSeeText('Topic:')
        ->assertSee('Another selected post')
        ->assertDontSee('Other post');
});

test('dashboard selected topic shows a sticky composer in the main panel', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Selected Topic', 'slug' => 'selected-topic']);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertSee('data-test="main-panel-composer-shell"', escape: false)
        ->assertSee('data-test="main-panel-composer"', escape: false)
        ->assertSee('data-test="main-panel-composer-attachments-button"', escape: false)
        ->assertSee('data-test="main-panel-composer-attachments-input"', escape: false)
        ->assertSee('wire:submit="sendQuickPost"', escape: false)
        ->assertSee('Message Selected Topic')
        ->assertSee('shrink-0 border-t border-neutral-200', escape: false);
});

test('dashboard main composer creates a published top level post', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Selected Topic', 'slug' => 'selected-topic']);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('quickPostBody', 'A smoother post from the composer.')
        ->call('sendQuickPost')
        ->assertHasNoErrors()
        ->assertSet('quickPostBody', '');

    $post = Post::query()->where('body', 'A smoother post from the composer.')->sole();

    expect($post->topic->is($topic))->toBeTrue()
        ->and($post->sender->is($workspace->principalForUser($user)))->toBeTrue()
        ->and($post->status)->toBe(PostStatus::Published)
        ->and($post->thread_id)->toBeNull();
});

test('dashboard main composer stores attachments with the published post', function () {
    Storage::fake('public');

    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Selected Topic', 'slug' => 'selected-topic']);
    $file = UploadedFile::fake()->create('brief.pdf', 128, 'application/pdf');

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('quickPostBody', 'A smoother post with an attachment.')
        ->set('quickPostUploads', [$file])
        ->call('sendQuickPost')
        ->assertHasNoErrors()
        ->assertSet('quickPostBody', '')
        ->assertSet('quickPostUploads', []);

    $post = Post::query()->where('body', 'A smoother post with an attachment.')->sole();
    $attachment = $post->attachments()->sole();

    expect($attachment->filename)->toBe('brief.pdf')
        ->and($attachment->path)->toContain('attachments/');

    Storage::disk('public')->assertExists($attachment->path);
});

test('dashboard main composer can remove a pending attachment before posting', function () {
    Storage::fake('public');

    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Selected Topic', 'slug' => 'selected-topic']);
    $removedFile = UploadedFile::fake()->create('remove-me.pdf', 128, 'application/pdf');
    $keptFile = UploadedFile::fake()->create('keep-me.pdf', 128, 'application/pdf');

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('quickPostBody', 'A smoother post with one attachment.')
        ->set('quickPostUploads', [$removedFile, $keptFile])
        ->assertSee('data-test="main-panel-composer-attachment-remove"', escape: false)
        ->call('removeQuickPostUpload', 0)
        ->assertSet('quickPostUploads', fn (array $uploads): bool => count($uploads) === 1 && $uploads[0]->getClientOriginalName() === 'keep-me.pdf')
        ->call('sendQuickPost')
        ->assertHasNoErrors();

    $post = Post::query()->where('body', 'A smoother post with one attachment.')->sole();

    expect($post->attachments()->pluck('filename')->all())->toBe(['keep-me.pdf']);
});

test('dashboard keeps thread replies out of top-level topic lists', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Selected Topic', 'slug' => 'selected-topic']);
    $sourcePost = Post::factory()->for($topic)->create([
        'body' => 'Top-level request',
        'status' => PostStatus::Published,
    ]);
    $thread = Thread::factory()->for($topic)->create([
        'parent_post_id' => $sourcePost->id,
        'title' => 'Top-level request',
    ]);
    Post::factory()->for($topic)->for($thread)->create([
        'body' => 'Threaded agent reply',
        'status' => PostStatus::Published,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertSee('Top-level request')
        ->assertDontSee('Threaded agent reply')
        ->assertSee('data-test="post-message-replies"', escape: false)
        ->assertSee('mt-2.5 inline-flex items-center gap-1.5 text-xs', escape: false)
        ->assertSee('flex size-5 items-center justify-center rounded border-2', escape: false)
        ->assertSee('data-test="post-message-reply-avatar"', escape: false)
        ->assertSee('1 reply');

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug, 'post' => $sourcePost->ulid, 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('Top-level request')
        ->assertSee('Threaded agent reply')
        ->assertSee('id="posts-panel"', escape: false)
        ->assertSee('id="thread-panel"', escape: false)
        ->assertSee('data-test="thread-op-replies-divider"', escape: false)
        ->assertSee('data-test="folder-post-message"', escape: false);
});

test('dashboard post messages open from the replies affordance instead of the whole row', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Selected Topic', 'slug' => 'selected-topic']);
    $post = Post::factory()->for($topic)->create([
        'body' => 'Open the thread panel from this row.',
        'status' => PostStatus::Published,
    ]);
    $thread = Thread::factory()->for($topic)->create([
        'parent_post_id' => $post->id,
        'title' => 'Open the thread panel from this row.',
    ]);
    Post::factory()->for($topic)->for($thread)->create([
        'body' => 'Thread reply',
        'status' => PostStatus::Published,
    ]);

    $postHref = route('dashboard', ['topic' => $topic->slug, 'post' => $post->ulid, 'panel' => 'posts']);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertSee('data-test="folder-post-message"', escape: false)
        ->assertSee('data-href="'.e($postHref).'"', escape: false)
        ->assertSee('data-test="post-message-replies"', escape: false)
        ->assertSee('href="'.e($postHref).'"', escape: false)
        ->assertDontSee('cursor-pointer rounded-lg px-2 py-4', escape: false)
        ->assertDontSee('$wire.openPost', escape: false)
        ->assertDontSee('block whitespace-pre-wrap hover:underline', escape: false);
});

test('post messages collapse bodies that are longer than ten lines', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    Post::factory()->for($topic)->create([
        'body' => collect(range(1, 11))->map(fn (int $line): string => "Line {$line}")->implode("\n"),
        'status' => PostStatus::Published,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertSee('data-test="post-message-body-toggle"', escape: false)
        ->assertSee('cursor-pointer text-xs font-medium', escape: false)
        ->assertSee('max-h-[10.5rem] overflow-hidden', escape: false)
        ->assertSee('Show more')
        ->assertSee('Show less');
});

test('post messages do not render a collapse control for short bodies', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    Post::factory()->for($topic)->create([
        'body' => collect(range(1, 10))->map(fn (int $line): string => "Line {$line}")->implode("\n"),
        'status' => PostStatus::Published,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertDontSee('data-test="post-message-body-toggle"', escape: false)
        ->assertDontSee('max-h-[10.5rem] overflow-hidden', escape: false);
});

test('dashboard opens a post thread panel from the feed', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Selected Topic', 'slug' => 'selected-topic']);
    $sourcePost = Post::factory()->for($topic)->create([
        'body' => 'Top-level request',
        'status' => PostStatus::Published,
    ]);
    $thread = Thread::factory()->for($topic)->create([
        'parent_post_id' => $sourcePost->id,
        'title' => 'Top-level request',
    ]);
    Post::factory()->for($topic)->for($thread)->create([
        'body' => 'Threaded agent reply',
        'status' => PostStatus::Published,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->call('openPost', $sourcePost->ulid)
        ->assertSet('selectedPostUlid', $sourcePost->ulid)
        ->assertSee('Top-level request')
        ->assertSee('Threaded agent reply')
        ->assertSee('id="posts-panel"', escape: false)
        ->assertSee('id="thread-panel"', escape: false)
        ->assertSee('data-test="folder-post-message"', escape: false)
        ->assertSee('data-test="thread-op-replies-divider"', escape: false)
        ->assertSee('data-test="dashboard-post-panel"', escape: false);
});

test('dashboard thread panel shows an inline reply composer', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Selected Topic', 'slug' => 'selected-topic']);
    $sourcePost = Post::factory()->for($topic)->create([
        'body' => 'Top-level request',
        'status' => PostStatus::Published,
        'sender_principal_id' => $workspace->principalForUser($user)->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug, 'post' => $sourcePost->ulid, 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('data-test="thread-panel-composer-shell"', escape: false)
        ->assertSee('data-test="thread-panel-composer"', escape: false)
        ->assertSee('data-test="thread-panel-composer-attachments-button"', escape: false)
        ->assertSee('data-test="thread-panel-composer-attachments-input"', escape: false)
        ->assertSee('wire:submit="sendThreadReply"', escape: false)
        ->assertSee('Reply...')
        ->assertSee('data-test="dashboard-post-panel"', escape: false)
        ->assertSee('flex min-h-0 flex-1 flex-col gap-6 overflow-auto', escape: false)
        ->assertSee('shrink-0 border-t border-neutral-200 bg-neutral-50/80', escape: false);
});

test('dashboard thread composer replies in the selected post thread', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Selected Topic', 'slug' => 'selected-topic']);
    $sourcePost = Post::factory()->for($topic)->create([
        'body' => 'Top-level request',
        'status' => PostStatus::Published,
        'sender_principal_id' => $workspace->principalForUser($user)->id,
    ]);
    $thread = Thread::factory()->for($topic)->create([
        'parent_post_id' => $sourcePost->id,
        'title' => 'Top-level request',
    ]);
    $firstReply = Post::factory()->for($topic)->for($thread)->create([
        'body' => 'Existing reply',
        'status' => PostStatus::Published,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedPostUlid', $firstReply->ulid)
        ->set('threadReplyBody', 'Replying from the thread composer.')
        ->call('sendThreadReply')
        ->assertHasNoErrors()
        ->assertSet('threadReplyBody', '');

    $reply = Post::query()->where('body', 'Replying from the thread composer.')->sole();

    expect($reply->topic->is($topic))->toBeTrue()
        ->and($reply->sender->is($workspace->principalForUser($user)))->toBeTrue()
        ->and($reply->status)->toBe(PostStatus::Published)
        ->and($reply->thread_id)->toBe($thread->id)
        ->and($reply->startedThread()->exists())->toBeFalse();
});

test('dashboard thread composer stores attachments with the reply', function () {
    Storage::fake('public');

    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Selected Topic', 'slug' => 'selected-topic']);
    $sourcePost = Post::factory()->for($topic)->create([
        'body' => 'Top-level request',
        'status' => PostStatus::Published,
        'sender_principal_id' => $workspace->principalForUser($user)->id,
    ]);
    $thread = Thread::factory()->for($topic)->create([
        'parent_post_id' => $sourcePost->id,
        'title' => 'Top-level request',
    ]);
    $file = UploadedFile::fake()->create('reply-brief.pdf', 128, 'application/pdf');

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedPostUlid', $sourcePost->ulid)
        ->set('threadReplyBody', 'Replying with an attachment.')
        ->set('threadReplyUploads', [$file])
        ->call('sendThreadReply')
        ->assertHasNoErrors()
        ->assertSet('threadReplyBody', '')
        ->assertSet('threadReplyUploads', []);

    $reply = Post::query()->where('body', 'Replying with an attachment.')->sole();
    $attachment = $reply->attachments()->sole();

    expect($reply->thread_id)->toBe($thread->id)
        ->and($attachment->filename)->toBe('reply-brief.pdf')
        ->and($attachment->path)->toContain('attachments/');

    Storage::disk('public')->assertExists($attachment->path);
});

test('dashboard thread composer can remove a pending attachment before replying', function () {
    Storage::fake('public');

    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Selected Topic', 'slug' => 'selected-topic']);
    $sourcePost = Post::factory()->for($topic)->create([
        'body' => 'Top-level request',
        'status' => PostStatus::Published,
        'sender_principal_id' => $workspace->principalForUser($user)->id,
    ]);
    Thread::factory()->for($topic)->create([
        'parent_post_id' => $sourcePost->id,
        'title' => 'Top-level request',
    ]);
    $removedFile = UploadedFile::fake()->create('remove-reply.pdf', 128, 'application/pdf');
    $keptFile = UploadedFile::fake()->create('keep-reply.pdf', 128, 'application/pdf');

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedPostUlid', $sourcePost->ulid)
        ->set('threadReplyBody', 'Replying with one attachment.')
        ->set('threadReplyUploads', [$removedFile, $keptFile])
        ->assertSee('data-test="thread-panel-composer-attachment-remove"', escape: false)
        ->call('removeThreadReplyUpload', 0)
        ->assertSet('threadReplyUploads', fn (array $uploads): bool => count($uploads) === 1 && $uploads[0]->getClientOriginalName() === 'keep-reply.pdf')
        ->call('sendThreadReply')
        ->assertHasNoErrors();

    $reply = Post::query()->where('body', 'Replying with one attachment.')->sole();

    expect($reply->attachments()->pluck('filename')->all())->toBe(['keep-reply.pdf']);
});

test('dashboard thread composer starts a thread from the selected top level post', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Selected Topic', 'slug' => 'selected-topic']);
    $sourcePost = Post::factory()->for($topic)->create([
        'body' => 'Top-level request without replies',
        'status' => PostStatus::Published,
        'sender_principal_id' => $workspace->principalForUser($user)->id,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedPostUlid', $sourcePost->ulid)
        ->set('threadReplyBody', 'Starting the thread inline.')
        ->call('sendThreadReply')
        ->assertHasNoErrors()
        ->assertSet('threadReplyBody', '');

    $reply = Post::query()->where('body', 'Starting the thread inline.')->sole();
    $thread = $sourcePost->fresh()->startedThread;

    expect($thread)->not->toBeNull()
        ->and($thread->parentPost->is($sourcePost))->toBeTrue()
        ->and($reply->thread_id)->toBe($thread->id)
        ->and($reply->thread->parentPost->is($sourcePost))->toBeTrue();
});

test('dashboard shows selected draft post in the main panel', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);
    $post = Post::factory()->for($topic)->create([
        'body' => 'Working body',
        'status' => PostStatus::Draft,
    ]);

    $response = $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug, 'post' => $post->ulid, 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('data-test="dashboard-post-panel"', escape: false)
        ->assertSee('Working body')
        ->assertDontSee('data-flux-breadcrumbs', escape: false)
        ->assertSeeText('Post')
        ->assertSee('form="dashboard-selected-post-form"', escape: false)
        ->assertSee('Save draft')
        ->assertSee('Post');

    expect($response->getContent())->not->toContain('>Draft<');
});

test('dashboard can save selected draft post', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);
    $post = Post::factory()->for($topic)->create([
        'body' => 'Draft body',
        'status' => PostStatus::Draft,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('selectedPostUlid', $post->ulid)
        ->set('postBody', 'Updated body')
        ->call('saveSelectedPost')
        ->assertHasNoErrors();

    expect($post->fresh()->body)->toBe('Updated body');
});

test('dashboard published post panel shows sender and topic', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);
    $post = Post::factory()->for($topic)->create([
        'body' => 'Published note',
        'status' => PostStatus::Published,
        'sender_principal_id' => $workspace->principalForUser($user)->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug, 'post' => $post->ulid, 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('data-test="post-message"', escape: false)
        ->assertSee('data-test="post-message-sender"', escape: false)
        ->assertSee($user->name)
        ->assertDontSee('#Design')
        ->assertSee('Move to drafts')
        ->assertDontSee('Return to draft');
});

test('dashboard post panel shows attachments', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $post = Post::factory()->for($topic)->create([
        'body' => 'Published note',
        'status' => PostStatus::Published,
    ]);
    Attachment::factory()->for($post)->create([
        'filename' => 'roadmap.pdf',
        'size' => 2048,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug, 'post' => $post->ulid, 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('Attachments')
        ->assertSee('roadmap.pdf')
        ->assertSee('2 KB')
        ->assertDontSee('wire:model="postUploads"', escape: false);
});

test('dashboard feed post messages show attachments', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $post = Post::factory()->for($topic)->create([
        'body' => 'Published note',
        'status' => PostStatus::Published,
    ]);
    Attachment::factory()->for($post)->create([
        'filename' => 'brief.pdf',
        'size' => 2048,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug, 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('data-test="post-message-attachments"', escape: false)
        ->assertSee('brief.pdf')
        ->assertSee('2 KB');
});

test('dashboard feed post messages show image attachments as thumbnails', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $post = Post::factory()->for($topic)->create([
        'body' => 'Published note',
        'status' => PostStatus::Published,
    ]);
    $attachment = Attachment::factory()->for($post)->create([
        'filename' => 'screenshot.png',
        'path' => 'attachments/screenshot.png',
        'mime_type' => 'image/png',
        'size' => 2048,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug, 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('data-test="post-message-image-attachment"', escape: false)
        ->assertSee('<img', escape: false)
        ->assertSee('screenshot.png')
        ->assertSee(route('attachments.show', ['attachment' => $attachment]), escape: false);
});

test('dashboard feed post messages link agent mentions to agent panel', function () {
    Queue::fake();

    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $agent = Agent::factory()->for($workspace)->create([
        'name' => 'Reviewer',
        'slug' => 'reviewer',
    ]);
    Post::factory()->for($topic)->create([
        'body' => '@reviewer Please review this.',
        'status' => PostStatus::Published,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug, 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('data-test="post-message-agent-mention"', escape: false)
        ->assertSee('>@reviewer</a>', escape: false)
        ->assertSee(route('dashboard', ['agent' => $agent->slug]), escape: false);
});

test('dashboard feed post messages render markdown safely', function () {
    Queue::fake();

    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    Agent::factory()->for($workspace)->create([
        'name' => 'Reviewer',
        'slug' => 'reviewer',
    ]);

    Post::factory()->for($topic)->create([
        'body' => implode("\n", [
            '## Specification',
            '',
            '@reviewer please review **bold text**.',
            '',
            '- First item',
            '- Second item',
            '',
            '<script>alert("bad")</script>',
        ]),
        'status' => PostStatus::Published,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug, 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('<h2>Specification</h2>', escape: false)
        ->assertSee('<strong>bold text</strong>', escape: false)
        ->assertSee('<ul>', escape: false)
        ->assertSee('<li>First item</li>', escape: false)
        ->assertSee('data-test="post-message-agent-mention"', escape: false)
        ->assertSee('>@reviewer</a>', escape: false)
        ->assertDontSee('<script>alert', escape: false);
});

test('attachment route serves image files inline', function () {
    Storage::fake('public');

    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create();
    $post = Post::factory()->for($topic)->create();
    Storage::disk('public')->put('attachments/screenshot.png', 'image-content');

    $attachment = Attachment::factory()->for($post)->create([
        'filename' => 'screenshot.png',
        'path' => 'attachments/screenshot.png',
        'mime_type' => 'image/png',
        'size' => 2048,
    ]);

    $this->actingAs($user)
        ->get(route('attachments.show', ['attachment' => $attachment]))
        ->assertOk()
        ->assertHeader('content-type', 'image/png');
});

test('dashboard saves pending attachments with selected draft post', function () {
    Storage::fake('public');

    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $post = Post::factory()->for($topic)->create([
        'body' => 'Draft brief',
        'status' => PostStatus::Draft,
    ]);
    $file = UploadedFile::fake()->create('brief.pdf', 128, 'application/pdf');

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('selectedPostUlid', $post->ulid)
        ->set('postBody', 'Draft body with attachment')
        ->set('postUploads', [$file])
        ->call('saveSelectedPost')
        ->assertHasNoErrors()
        ->assertSet('postUploads', []);

    expect($post->attachments()->count())->toBe(1);
    expect($post->attachments()->first()->path)->toContain('attachments/');
});

test('dashboard can delete attachments from selected draft post', function () {
    Storage::fake('public');

    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $post = Post::factory()->for($topic)->create([
        'body' => 'Draft brief',
        'status' => PostStatus::Draft,
    ]);
    $attachment = Attachment::factory()->for($post)->create([
        'path' => 'attachments/report.pdf',
    ]);

    Storage::disk('public')->put($attachment->path, 'report');

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('selectedPostUlid', $post->ulid)
        ->call('deleteSelectedPostAttachment', $attachment->id)
        ->assertHasNoErrors();

    expect($attachment->fresh()->deleted_at)->not->toBeNull();
    Storage::disk('public')->assertMissing($attachment->path);
});

test('dashboard shows workspace agents in the right rail', function () {
    [$user, $workspace] = userWithWorkspace();

    $agent = Agent::factory()->for($workspace)->create(['name' => 'Rail Agent']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Agents')
        ->assertSee('New agent')
        ->assertSee($agent->name)
        ->assertSee("wire:click=\"openAgent('{$agent->slug}')\"", escape: false)
        ->assertDontSee(route('agents.show', ['agent' => $agent->slug]), escape: false);
});

test('dashboard shows selected agent details in the main panel', function () {
    [$user, $workspace] = userWithWorkspace();

    $agent = Agent::factory()->for($workspace)->create([
        'name' => 'Research Agent',
        'slug' => 'research-agent',
    ]);

    AgentVersion::factory()->for($agent)->create([
        'provider' => Provider::OpenAI,
        'model' => 'o4-mini',
        'prompt' => 'Research carefully.',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['agent' => $agent->slug]))
        ->assertOk()
        ->assertSee('data-test="dashboard-agent-panel"', escape: false)
        ->assertSee('h-dvh overflow-hidden bg-white', escape: false)
        ->assertSee('grid h-full min-h-0 min-w-0 flex-1', escape: false)
        ->assertSee('flex min-h-0 flex-1 flex-col gap-4 overflow-auto', escape: false)
        ->assertSee('Research Agent')
        ->assertSee('Agent details')
        ->assertSee('New version')
        ->assertSee('Version history')
        ->assertSee('o4-mini')
        ->assertSee('Research carefully.')
        ->assertSee('xl:grid-cols-[16rem_minmax(0,1fr)]', escape: false)
        ->assertDontSee('xl:grid-cols-[16rem_minmax(0,1fr)_32rem]', escape: false);
});

test('dashboard can save selected agent details', function () {
    [$user, $workspace] = userWithWorkspace();

    $agent = Agent::factory()->for($workspace)->create(['slug' => 'research-agent']);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedAgentSlug', $agent->slug)
        ->set('selectedAgentName', 'Updated Agent')
        ->call('saveSelectedAgentDetails')
        ->assertHasNoErrors();

    expect($agent->fresh()->name)->toBe('Updated Agent');
});

test('dashboard can save selected agent version', function () {
    [$user, $workspace] = userWithWorkspace();

    $agent = Agent::factory()->for($workspace)->create(['slug' => 'research-agent']);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedAgentSlug', $agent->slug)
        ->set('selectedAgentProvider', Provider::OpenAI->value)
        ->set('selectedAgentModel', 'o4-mini')
        ->set('selectedAgentReasoningEffort', ReasoningEffort::Low->value)
        ->set('selectedAgentPrompt', 'New panel prompt.')
        ->call('saveSelectedAgentVersion')
        ->assertHasNoErrors();

    $version = $agent->versions()->latest('version')->first();

    expect($version)->not->toBeNull()
        ->and($version->provider)->toBe(Provider::OpenAI)
        ->and($version->model)->toBe('o4-mini')
        ->and($version->reasoning_effort)->toBe(ReasoningEffort::Low)
        ->and($version->prompt)->toBe('New panel prompt.');
});

test('dashboard can create an agent from the right rail', function () {
    [$user, $workspace] = userWithWorkspace();

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('agentName', 'Rail Agent')
        ->set('provider', Provider::OpenAI->value)
        ->set('model', 'o4-mini')
        ->set('reasoningEffort', ReasoningEffort::Low->value)
        ->set('prompt', 'Help in the sidebar.')
        ->call('createAgent')
        ->assertHasNoErrors();

    $agent = $workspace->agents()->where('name', 'Rail Agent')->first();

    expect($agent)->not->toBeNull();
    expect($agent->versions)->toHaveCount(1);
    expect($agent->versions->first()->provider)->toBe(Provider::OpenAI);
});

test('dashboard shows new post action', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertSee(e(route('posts.create', ['topic' => $topic->slug])), escape: false)
        ->assertDontSee(e(route('dashboard', ['topic' => $topic->slug, 'action' => 'new-post', 'panel' => 'posts'])), escape: false);
});

test('dashboard shows new post form in the main panel', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);

    $this->actingAs($user)
        ->get(route('posts.create', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertSee('data-test="dashboard-post-create-panel"', escape: false)
        ->assertSee('id="dashboard-new-post-form"', escape: false)
        ->assertSee('form="dashboard-new-post-form"', escape: false)
        ->assertSee('New post')
        ->assertSeeText('Post')
        ->assertSee('Save draft')
        ->assertSee('Post')
        ->assertSee('Design');
});

test('dashboard shows new post form in the post panel without a selected topic', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);

    $this->actingAs($user)
        ->get(route('posts.create'))
        ->assertOk()
        ->assertSee('data-test="dashboard-post-create-panel"', escape: false)
        ->assertSee('id="dashboard-new-post-form"', escape: false)
        ->assertSee('form="dashboard-new-post-form"', escape: false)
        ->assertSee('New post')
        ->assertSee('Save draft')
        ->assertSee('Post')
        ->assertSee($topic->name);
});

test('dashboard keeps route-based new post form open while attachments upload', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $file = UploadedFile::fake()->create('brief.pdf', 128, 'application/pdf');

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('creatingPostFromRoute', true)
        ->set('selectedTopicSlug', $topic->slug)
        ->set('newPostUploads', [$file])
        ->assertSet('creatingPostFromRoute', true)
        ->assertSee('id="dashboard-new-post-form"', escape: false)
        ->assertSee('form="dashboard-new-post-form"', escape: false);
});

test('dashboard can create a draft post in the main panel', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('panelAction', 'new-post')
        ->set('newPostBody', 'Draft body')
        ->set('newPostTopicId', $topic->id)
        ->call('createDashboardPost')
        ->assertHasNoErrors()
        ->assertSet('panelAction', null)
        ->assertSet('selectedTopicSlug', $topic->slug)
        ->assertSet('selectedPostUlid', fn (?string $ulid): bool => filled($ulid));

    $post = $topic->posts()->where('body', 'Draft body')->first();

    expect($post)->not->toBeNull()
        ->and($post->status)->toBe(PostStatus::Draft);
});

test('dashboard can make a new post actionable in the main panel', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('panelAction', 'new-post')
        ->set('newPostBody', 'Actionable body')
        ->set('newPostTopicId', $topic->id)
        ->call('sendDashboardPost')
        ->assertHasNoErrors()
        ->assertSet('panelAction', null)
        ->assertSet('selectedTopicSlug', $topic->slug)
        ->assertSet('selectedPostUlid', fn (?string $ulid): bool => filled($ulid));

    $post = $topic->posts()->where('body', 'Actionable body')->first();
    $senderPrincipal = $workspace->principalForUser($user);

    expect($post)->not->toBeNull()
        ->and($post->sender_principal_id)->toBe($senderPrincipal->id)
        ->and($post->status)->toBe(PostStatus::Published);
});

test('dashboard creates agent tasks from mentions when sending a new post', function () {
    Queue::fake();

    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    Agent::factory()->for($workspace)->create(['name' => 'Researcher']);
    Agent::factory()->for($workspace)->create(['name' => 'Reviewer']);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('panelAction', 'new-post')
        ->set('newPostBody', '@researcher @reviewer Please both review this.')
        ->set('newPostTopicId', $topic->id)
        ->call('sendDashboardPost')
        ->assertHasNoErrors();

    $post = $topic->posts()->where('body', '@researcher @reviewer Please both review this.')->first();

    expect($post)->not->toBeNull()
        ->and($post->agentTasks)->toHaveCount(2)
        ->and($post->agentTasks->pluck('event_type')->unique()->values()->all())->toBe([AgentTask::EventPostMentioned])
        ->and($post->agentTasks->pluck('available_at')->filter())->toHaveCount(2);
});

test('dashboard posts a new post to a topic', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('panelAction', 'new-post')
        ->set('newPostBody', 'For the topic')
        ->set('newPostTopicId', $topic->id)
        ->call('sendDashboardPost')
        ->assertHasNoErrors();

    $post = $topic->posts()->where('body', 'For the topic')->first();
    $senderPrincipal = $workspace->principalForUser($user);

    expect($post)->not->toBeNull()
        ->and($post->sender_principal_id)->toBe($senderPrincipal->id)
        ->and($post->status)->toBe(PostStatus::Published);
});

test('dashboard sets sender when posting a new post', function () {
    [$user, $workspace] = userWithWorkspace();
    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $senderPrincipal = $workspace->principalForUser($user);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('panelAction', 'new-post')
        ->set('newPostBody', 'Topic body')
        ->set('newPostTopicId', $topic->id)
        ->call('sendDashboardPost')
        ->assertHasNoErrors();

    $post = $topic->posts()->where('body', 'Topic body')->first();

    expect($post)->not->toBeNull()
        ->and($post->sender_principal_id)->toBe($senderPrincipal->id)
        ->and($post->status)->toBe(PostStatus::Published);
});

test('dashboard shows mobile bottom navigation with topics active by default', function () {
    [$user, $workspace] = userWithWorkspace();

    Topic::factory()->for($workspace)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-mobile-nav="topics"', escape: false)
        ->assertSee('data-mobile-nav="posts"', escape: false)
        ->assertSee('aria-pressed="true"', escape: false)
        ->assertSee('data-mobile-panel="topics"', escape: false)
        ->assertDontSee('xl:sticky xl:top-6')
        ->assertSee('Agents');
});

test('dashboard can render the topics mobile panel as active', function () {
    [$user, $workspace] = userWithWorkspace();

    Topic::factory()->for($workspace)->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['panel' => 'topics']))
        ->assertOk()
        ->assertSee('data-mobile-nav="topics"', escape: false)
        ->assertSee('aria-pressed="true"', escape: false)
        ->assertSee('hidden xl:flex', escape: false);
});

test('dashboard feed panel shows a top-level new post action', function () {
    [$user, $workspace] = userWithWorkspace();

    Topic::factory()->for($workspace)->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['panel' => 'posts']))
        ->assertOk()
        ->assertSee('Feed')
        ->assertDontSee('Select a topic')
        ->assertSee('data-mobile-panel="posts"', escape: false)
        ->assertSee('New post')
        ->assertSee(e(route('posts.create')), escape: false)
        ->assertSee('data-mobile-nav="posts"', escape: false);
});

test('topic page renders posts as message feed items', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design']);
    $post = Post::factory()->for($topic)->create([
        'created_at' => now()->subMinutes(13),
        'status' => PostStatus::Published,
        'sender_principal_id' => $workspace->principalForUser($user)->id,
    ]);
    Attachment::factory()->for($post)->create();

    $this->actingAs($user)
        ->get(route('topics.show', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertSee('data-test="folder-title"', escape: false)
        ->assertSeeText('Design')
        ->assertSee('Agents')
        ->assertSee('flex w-full min-w-0 items-center justify-between gap-3', escape: false)
        ->assertSee('hidden shrink-0 items-center gap-3 md:flex', escape: false)
        ->assertSee('flex shrink-0 items-center gap-2 md:hidden', escape: false)
        ->assertSee('data-test="folder-controls-toggle"', escape: false)
        ->assertSee('data-test="folder-controls-drawer"', escape: false)
        ->assertSee('flex w-full flex-wrap items-center justify-between gap-2 md:hidden', escape: false)
        ->assertDontSee('x-if="view === \'icons\'"', escape: false)
        ->assertDontSee('x-if="view === \'list\'"', escape: false)
        ->assertDontSee('flex h-36 w-28 flex-col items-center gap-1', escape: false)
        ->assertSee('data-test="post-message"', escape: false)
        ->assertSee('data-test="post-message-actions"', escape: false)
        ->assertSee(e(route('posts.show', ['post' => $post])), escape: false)
        ->assertSee('min-w-0 flex-1', escape: false)
        ->assertDontSee('data-test="folder-list-sort-header"', escape: false)
        ->assertSee('data-sort-sent=', escape: false)
        ->assertSee('data-sort-attachments=', escape: false)
        ->assertSee('wire:key="folder-post-message-', escape: false)
        ->assertSee('rounded-lg py-4 first:pt-0 last:pb-0', escape: false)
        ->assertDontSee('rounded-lg px-2 py-4', escape: false)
        ->assertSeeText($user->name)
        ->assertDontSee('data-test="post-message-topic"', escape: false)
        ->assertDontSee('#Design')
        ->assertSeeText('13 minutes ago')
        ->assertSee('grid grid-cols-1 items-stretch gap-3 xl:flex-1 xl:auto-rows-fr xl:grid-cols-[minmax(0,1fr)_19rem]', escape: false)
        ->assertSee('xl:h-full', escape: false)
        ->assertDontSee('xl:sticky xl:top-6');
});

test('topic post list uses insertion order for channel order', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create();
    Post::factory()->for($topic)->create([
        'body' => 'Older post',
        'status' => PostStatus::Published,
    ]);

    $firstTie = Post::factory()->for($topic)->create([
        'body' => 'First tied post',
        'status' => PostStatus::Published,
    ]);

    $secondTie = Post::factory()->for($topic)->create([
        'body' => 'Second tied post',
        'status' => PostStatus::Published,
    ]);

    Post::factory()->for($topic)->create([
        'body' => 'Newest post',
        'status' => PostStatus::Published,
    ]);

    expect($secondTie->id)->toBeGreaterThan($firstTie->id);

    $response = $this->actingAs($user)
        ->get(route('topics.show', ['topic' => $topic->slug]))
        ->assertOk();

    preg_match_all('/data-post-preview="([^"]+)"/', $response->getContent(), $matches);

    expect($matches[1])->toBe([
        'Older post',
        'First tied post',
        'Second tied post',
        'Newest post',
    ]);
});
