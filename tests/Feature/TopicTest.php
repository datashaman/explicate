<?php

use App\Enums\PostStatus;
use App\Enums\Provider;
use App\Enums\ReasoningEffort;
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
    $post = Post::factory()->for($topic)->for($thread)->create(['title' => 'Review note']);

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
    $post = Post::factory()->for($topic)->create(['title' => 'Dashboard draft']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Topics')
        ->assertSee('Feed')
        ->assertSee('Drafts')
        ->assertSee('Archived')
        ->assertSee($topic->name)
        ->assertDontSee($post->title)
        ->assertSee('Select a topic')
        ->assertSee('Choose a topic to view its feed.');
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
        ->assertSee(e(route('dashboard', ['folder' => 'feed', 'panel' => 'posts'])), escape: false)
        ->assertSee(e(route('dashboard', ['folder' => 'drafts', 'panel' => 'posts'])), escape: false)
        ->assertSee(e(route('dashboard', ['folder' => 'archived', 'panel' => 'posts'])), escape: false)
        ->assertSee('data-test="system-folder-feed-count"', escape: false)
        ->assertSee('data-test="system-folder-drafts-count"', escape: false);
});

test('dashboard system draft folder shows draft posts across topics', function () {
    [$user, $workspace] = userWithWorkspace();

    $design = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);
    $engineering = Topic::factory()->for($workspace)->create(['slug' => 'engineering']);
    $userPrincipal = $workspace->principalForUser($user);

    $designDraft = Post::factory()->for($design)->create([
        'title' => 'Working draft',
        'updated_at' => now()->subMinutes(7),
        'status' => PostStatus::Draft,
    ]);

    Post::factory()->for($engineering)->create([
        'title' => 'Engineering sent',
        'status' => PostStatus::Published,
        'sender_principal_id' => $userPrincipal->id,
    ]);

    $response = $this->actingAs($user)
        ->get(route('dashboard', ['folder' => 'drafts', 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('data-test="folder-title"', escape: false)
        ->assertSee('Drafts')
        ->assertSee('Working draft')
        ->assertSee(e(route('dashboard', [
            'folder' => 'drafts',
            'topic' => $design->slug,
            'post' => $designDraft->slug,
            'panel' => 'posts',
        ])), escape: false)
        ->assertDontSee('data-test="folder-list-sort-from"', escape: false)
        ->assertDontSee('data-test="folder-list-sort-sender"', escape: false)
        ->assertDontSeeText('Author')
        ->assertSee('data-test="folder-list-sort-topic"', escape: false)
        ->assertSee('data-sort-topic=', escape: false)
        ->assertSeeText('Topic:')
        ->assertSeeText('Design')
        ->assertSeeText('Saved:')
        ->assertSeeText('7 minutes ago')
        ->assertSee('data-test="folder-list-sort-saved"', escape: false)
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
        'title' => 'Hidden draft',
        'status' => PostStatus::Draft,
    ]);

    Post::factory()->for($topic)->create([
        'title' => 'Visible post',
        'status' => PostStatus::Published,
        'sender_principal_id' => $userPrincipal->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['folder' => 'feed', 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('Visible post')
        ->assertDontSee('Hidden draft');
});

test('dashboard feed folder shows all published topic posts', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);
    $userPrincipal = $workspace->principalForUser($user);

    Post::factory()->for($topic)->create([
        'title' => 'First topic post',
        'updated_at' => now()->subMinutes(9),
        'status' => PostStatus::Published,
        'sender_principal_id' => $userPrincipal->id,
    ]);

    Post::factory()->for($topic)->create([
        'title' => 'Second topic post',
        'status' => PostStatus::Published,
        'sender_principal_id' => $userPrincipal->id,
    ]);

    Post::factory()->for($topic)->create([
        'title' => 'Third topic post',
        'status' => PostStatus::Published,
        'sender_principal_id' => $userPrincipal->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['folder' => 'feed', 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('data-test="folder-title"', escape: false)
        ->assertSeeText('Feed')
        ->assertSee('First topic post')
        ->assertSee('Second topic post')
        ->assertSee('Third topic post')
        ->assertDontSeeText('Author')
        ->assertSee('data-test="folder-list-sort-sender"', escape: false)
        ->assertSeeText('Sender:')
        ->assertSeeText($user->name)
        ->assertSee('data-test="folder-list-sort-topic"', escape: false)
        ->assertSeeText('Topic:')
        ->assertSeeText('Design')
        ->assertSeeText('Sent:')
        ->assertSeeText('9 minutes ago');
});

test('dashboard post panel returns to the selected folder before the post topic', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);
    $post = Post::factory()->for($topic)->create([
        'title' => 'Feed post',
        'status' => PostStatus::Published,
        'sender_principal_id' => $workspace->principalForUser($user)->id,
    ]);

    $this->actingAs($user);

    $component = Livewire::test('pages::dashboard');
    $component->instance()->selectedSystemFolderSlug = 'feed';
    $component->instance()->selectedTopicSlug = $topic->slug;
    $component->instance()->selectedPostSlug = $post->slug;

    expect($component->instance()->postsPanelReturnRoute())
        ->toBe(route('dashboard', ['folder' => 'feed', 'panel' => 'posts']))
        ->and($component->instance()->postsPanelReturnLabel())
        ->toBe('Feed');
});

test('dashboard post panel return label matches selected topic context', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);
    $post = Post::factory()->for($topic)->create([
        'title' => 'Design draft',
        'status' => PostStatus::Draft,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug, 'post' => $post->slug, 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('data-test="posts-panel-return"', escape: false)
        ->assertSee(e(route('dashboard', ['topic' => $topic->slug, 'panel' => 'posts'])), escape: false)
        ->assertSeeText('Design');
});

test('dashboard archived folder shows archived feed', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);
    $userPrincipal = $workspace->principalForUser($user);

    Post::factory()->for($topic)->create([
        'title' => 'Archived post',
        'updated_at' => now()->subMinutes(11),
        'status' => PostStatus::Archived,
        'sender_principal_id' => $userPrincipal->id,
    ]);

    $response = $this->actingAs($user)
        ->get(route('dashboard', ['folder' => 'archived', 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('data-test="folder-title"', escape: false)
        ->assertSeeText('Archived')
        ->assertSee('Archived post')
        ->assertDontSeeText('Author')
        ->assertSee('data-test="folder-list-sort-sender"', escape: false)
        ->assertSeeText('Sender:')
        ->assertSeeText($user->name)
        ->assertSee('data-test="folder-list-sort-topic"', escape: false)
        ->assertSeeText('Topic:')
        ->assertSeeText('Design')
        ->assertSeeText('Sent:')
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
        'title' => 'Design draft',
        'status' => PostStatus::Draft,
    ]);

    Post::factory()->for($design)->create([
        'title' => 'Design published',
        'status' => PostStatus::Published,
    ]);

    Post::factory()->for($design)->create([
        'title' => 'Design archived',
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
        'title' => 'Selected post',
        'status' => PostStatus::Published,
    ]);
    Post::factory()->for($otherTopic)->create(['title' => 'Other post']);
    Post::factory()->for($selectedTopic)->create([
        'title' => 'Another selected post',
        'status' => PostStatus::Published,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $selectedTopic->slug]))
        ->assertOk()
        ->assertSee('Selected Topic')
        ->assertSee($selectedPost->title)
        ->assertSee(e(route('dashboard', ['topic' => $selectedTopic->slug, 'post' => $selectedPost->slug, 'panel' => 'posts'])), escape: false)
        ->assertDontSee(route('posts.show', ['post' => $selectedPost]), escape: false)
        ->assertDontSee('data-test="folder-list-sort-topic"', escape: false)
        ->assertDontSeeText('Topic:')
        ->assertSee('Another selected post')
        ->assertDontSee('Other post');
});

test('dashboard shows selected draft post in the main panel', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);
    $post = Post::factory()->for($topic)->create([
        'title' => 'Working brief',
        'body' => 'Working body',
        'status' => PostStatus::Draft,
    ]);

    $response = $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug, 'post' => $post->slug, 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('data-test="dashboard-post-panel"', escape: false)
        ->assertSee('Working brief')
        ->assertSee('Working body')
        ->assertDontSee('data-flux-breadcrumbs', escape: false)
        ->assertSeeText('Title')
        ->assertSeeText('Body')
        ->assertSee('form="dashboard-selected-post-form"', escape: false)
        ->assertSee('Save draft')
        ->assertSee('Post');

    expect($response->getContent())->not->toContain('>Draft<');
});

test('dashboard can save selected draft post', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);
    $post = Post::factory()->for($topic)->create([
        'title' => 'Draft brief',
        'body' => 'Draft body',
        'status' => PostStatus::Draft,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('selectedPostSlug', $post->slug)
        ->set('postTitle', 'Updated brief')
        ->set('postBody', 'Updated body')
        ->call('saveSelectedPost')
        ->assertHasNoErrors();

    expect($post->fresh())
        ->title->toBe('Updated brief')
        ->body->toBe('Updated body');
});

test('dashboard published post panel shows sender and topic', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);
    $post = Post::factory()->for($topic)->create([
        'title' => 'Published note',
        'status' => PostStatus::Published,
        'sender_principal_id' => $workspace->principalForUser($user)->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug, 'post' => $post->slug, 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('Sender')
        ->assertSee($user->name)
        ->assertSee('Topic')
        ->assertSee('Design')
        ->assertSee('Move to drafts')
        ->assertDontSee('Return to draft');
});

test('dashboard post panel shows attachments', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $post = Post::factory()->for($topic)->create([
        'title' => 'Published note',
        'status' => PostStatus::Published,
    ]);
    Attachment::factory()->for($post)->create([
        'filename' => 'roadmap.pdf',
        'size' => 2048,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug, 'post' => $post->slug, 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('Attachments')
        ->assertSee('roadmap.pdf')
        ->assertSee('2 KB')
        ->assertDontSee('type="file"', escape: false);
});

test('dashboard saves pending attachments with selected draft post', function () {
    Storage::fake('public');

    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $post = Post::factory()->for($topic)->create([
        'title' => 'Draft brief',
        'status' => PostStatus::Draft,
    ]);
    $file = UploadedFile::fake()->create('brief.pdf', 128, 'application/pdf');

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('selectedPostSlug', $post->slug)
        ->set('postTitle', 'Draft brief')
        ->set('postBody', '')
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
        'title' => 'Draft brief',
        'status' => PostStatus::Draft,
    ]);
    $attachment = Attachment::factory()->for($post)->create([
        'path' => 'attachments/report.pdf',
    ]);

    Storage::disk('public')->put($attachment->path, 'report');

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('selectedPostSlug', $post->slug)
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

test('dashboard gives assigned agents prominence in the right rail', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $available = Agent::factory()->for($workspace)->create(['name' => 'Available Agent']);
    $assigned = Agent::factory()->for($workspace)->create(['name' => 'Assigned Agent']);
    $topic->agents()->attach($assigned);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug, 'panel' => 'agents']))
        ->assertOk()
        ->assertSee('Assigned')
        ->assertSee('Available')
        ->assertSeeInOrder(['Assigned', 'Assigned Agent', 'Available', 'Available Agent'])
        ->assertSee('border-l-2 border-amber-400', escape: false)
        ->assertSee('data-test="workspace-agent-row-'.$assigned->slug.'"', escape: false)
        ->assertSee('data-test="workspace-agent-row-'.$available->slug.'"', escape: false);
});

test('dashboard shows selected agent details in the right panel', function () {
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
        ->get(route('dashboard', ['panel' => 'agents', 'agent' => $agent->slug]))
        ->assertOk()
        ->assertSee('data-test="dashboard-agent-panel"', escape: false)
        ->assertSee('Research Agent')
        ->assertSee('Agent details')
        ->assertSee('New version')
        ->assertSee('Version history')
        ->assertSee('o4-mini')
        ->assertSee('Research carefully.')
        ->assertSee('xl:grid-cols-[16rem_minmax(0,1fr)_32rem]', escape: false);
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
        ->assertSeeText('Title')
        ->assertSeeText('Body')
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
        ->set('newPostTitle', 'New draft')
        ->set('newPostBody', 'Draft body')
        ->set('newPostTopicId', $topic->id)
        ->call('createDashboardPost')
        ->assertHasNoErrors()
        ->assertSet('panelAction', null)
        ->assertSet('selectedTopicSlug', $topic->slug)
        ->assertSet('selectedPostSlug', 'new-draft');

    $post = $topic->posts()->where('title', 'New draft')->first();

    expect($post)->not->toBeNull()
        ->and($post->body)->toBe('Draft body')
        ->and($post->status)->toBe(PostStatus::Draft);
});

test('dashboard can make a new post actionable in the main panel', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('panelAction', 'new-post')
        ->set('newPostTitle', 'Ready to send')
        ->set('newPostBody', 'Actionable body')
        ->set('newPostTopicId', $topic->id)
        ->call('sendDashboardPost')
        ->assertHasNoErrors()
        ->assertSet('panelAction', null)
        ->assertSet('selectedTopicSlug', $topic->slug)
        ->assertSet('selectedPostSlug', 'ready-to-send');

    $post = $topic->posts()->where('title', 'Ready to send')->first();
    $senderPrincipal = $workspace->principalForUser($user);

    expect($post)->not->toBeNull()
        ->and($post->body)->toBe('Actionable body')
        ->and($post->sender_principal_id)->toBe($senderPrincipal->id)
        ->and($post->status)->toBe(PostStatus::Published);
});

test('dashboard can assign agents when sending a new post', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $agents = Agent::factory()->count(2)->for($workspace)->create();
    $topic->agents()->attach($agents->pluck('id'));

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('panelAction', 'new-post')
        ->set('newPostTitle', 'Agent assignment')
        ->set('newPostBody', 'Please both review this.')
        ->set('newPostTopicId', $topic->id)
        ->set('newPostAgentIds', $agents->pluck('id')->all())
        ->call('sendDashboardPost')
        ->assertHasNoErrors();

    $post = $topic->posts()->where('title', 'Agent assignment')->first();

    expect($post)->not->toBeNull()
        ->and($post->agentTasks)->toHaveCount(2)
        ->and($post->agentTasks->pluck('event_type')->unique()->values()->all())->toBe([AgentTask::EventPostAssigned])
        ->and($post->agentTasks->pluck('available_at')->filter())->toHaveCount(2);
});

test('dashboard posts a new post to a topic', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('panelAction', 'new-post')
        ->set('newPostTitle', 'Topic note')
        ->set('newPostBody', 'For the topic')
        ->set('newPostTopicId', $topic->id)
        ->call('sendDashboardPost')
        ->assertHasNoErrors();

    $post = $topic->posts()->where('title', 'Topic note')->first();
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
        ->set('newPostTitle', 'Topic dashboard post')
        ->set('newPostBody', 'Topic body')
        ->set('newPostTopicId', $topic->id)
        ->call('sendDashboardPost')
        ->assertHasNoErrors();

    $post = $topic->posts()->where('title', 'Topic dashboard post')->first();

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
        ->assertSee('data-mobile-nav="agents"', escape: false)
        ->assertSee('aria-pressed="true"', escape: false)
        ->assertSee('data-mobile-panel="topics"', escape: false)
        ->assertSee('data-mobile-panel="agents"', escape: false)
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

test('dashboard without a selected topic shows a top-level new post action', function () {
    [$user, $workspace] = userWithWorkspace();

    Topic::factory()->for($workspace)->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['panel' => 'posts']))
        ->assertOk()
        ->assertSee('Select a topic')
        ->assertSee('data-mobile-panel="topics"', escape: false)
        ->assertSee('New post')
        ->assertSee(e(route('posts.create')), escape: false)
        ->assertSee('data-mobile-nav="posts"', escape: false)
        ->assertSee('disabled', escape: false);
});

test('dashboard selected topic shows attach and detach actions in the agents rail', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'selected-topic']);
    $attachedAgent = Agent::factory()->for($workspace)->create(['name' => 'Attached Agent']);
    $availableAgent = Agent::factory()->for($workspace)->create(['name' => 'Available Agent']);

    $topic->agents()->attach($attachedAgent);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug, 'panel' => 'agents']))
        ->assertOk()
        ->assertSee('Attached Agent')
        ->assertSee('Available Agent')
        ->assertSee('Attach')
        ->assertSee('Detach');
});

test('topic page left aligns post icons in icon view', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design']);
    $post = Post::factory()->for($topic)->create([
        'updated_at' => now()->subMinutes(13),
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
        ->assertSee('x-if="view === \'icons\'"', escape: false)
        ->assertSee('x-if="view === \'list\'"', escape: false)
        ->assertSee('flex h-36 w-28 flex-col items-center gap-1', escape: false)
        ->assertSee('line-clamp-2 min-h-8 w-full text-xs leading-4', escape: false)
        ->assertSee('flex min-h-12 items-center gap-3', escape: false)
        ->assertSee('min-w-0 flex-1', escape: false)
        ->assertSee('block truncate text-sm', escape: false)
        ->assertSee('data-test="folder-item-attachments"', escape: false)
        ->assertSee('title="1 attachment"', escape: false)
        ->assertSee('data-test="folder-list-sort-header"', escape: false)
        ->assertSee('data-test="folder-list-sort-name"', escape: false)
        ->assertSee('data-test="folder-list-sort-sender"', escape: false)
        ->assertSee('data-test="folder-list-sort-sent"', escape: false)
        ->assertSee('data-test="folder-list-sort-attachments"', escape: false)
        ->assertSee('data-sort-sent=', escape: false)
        ->assertSee('data-sort-attachments=', escape: false)
        ->assertSee('wire:key="folder-icon-', escape: false)
        ->assertSee('wire:key="folder-list-', escape: false)
        ->assertSeeText('Sender:')
        ->assertSeeText($user->name)
        ->assertSeeText('Sent:')
        ->assertSeeText('13 minutes ago')
        ->assertSee('grid grid-cols-1 items-stretch gap-3 xl:flex-1 xl:auto-rows-fr xl:grid-cols-[minmax(0,1fr)_19rem]', escape: false)
        ->assertSee('xl:h-full', escape: false)
        ->assertDontSee('xl:sticky xl:top-6');
});

test('topic post list has a deterministic default order', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create();
    $timestamp = now()->subMinutes(10);

    Post::factory()->for($topic)->create([
        'title' => 'Older post',
        'status' => PostStatus::Published,
        'updated_at' => now()->subHour(),
    ]);

    $firstTie = Post::factory()->for($topic)->create([
        'title' => 'First tied post',
        'status' => PostStatus::Published,
        'updated_at' => $timestamp,
    ]);

    $secondTie = Post::factory()->for($topic)->create([
        'title' => 'Second tied post',
        'status' => PostStatus::Published,
        'updated_at' => $timestamp,
    ]);

    Post::factory()->for($topic)->create([
        'title' => 'Newest post',
        'status' => PostStatus::Published,
        'updated_at' => now(),
    ]);

    expect($secondTie->id)->toBeGreaterThan($firstTie->id);

    $this->actingAs($user)
        ->get(route('topics.show', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertSeeInOrder([
            'Newest post',
            'Second tied post',
            'First tied post',
            'Older post',
        ]);
});

test('topic page agent rail labels attach and detach actions clearly', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create();
    $attachedAgent = Agent::factory()->for($workspace)->create(['name' => 'Attached Agent']);
    $availableAgent = Agent::factory()->for($workspace)->create(['name' => 'Available Agent']);

    $topic->agents()->attach($attachedAgent);

    $this->actingAs($user)
        ->get(route('topics.show', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertSee('Attached Agent')
        ->assertSee('Available Agent')
        ->assertSee('Detach')
        ->assertSee('Attach')
        ->assertSee('Detach this agent from the topic?', escape: false);
});

test('topic page with only available agents does not render detach confirmation copy', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create();
    Agent::factory()->for($workspace)->create(['name' => 'Available Agent']);

    $this->actingAs($user)
        ->get(route('topics.show', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertSee('Attach')
        ->assertDontSee('Detach this agent from the topic?');
});
