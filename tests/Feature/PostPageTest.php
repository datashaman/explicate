<?php

use App\Enums\PostStatus;
use App\Models\Attachment;
use App\Models\Post;
use App\Models\Thread;
use App\Models\Topic;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

beforeEach(function () {
    [$this->user, $this->workspace] = userWithWorkspace();
    $this->topic = Topic::factory()->for($this->workspace)->create();
    $this->post = Post::factory()->for(Thread::factory()->forTopic($this->topic))->create();
});

test('post page loads', function () {
    $this->actingAs($this->user)
        ->get(route('dashboard', ['post' => $this->post->ulid]))
        ->assertOk()
        ->assertSee('data-test="dashboard-post-panel"', escape: false);
});

test('draft post page does not show redundant draft status', function () {
    $this->post->update(['body' => 'Working note']);

    $response = $this->actingAs($this->user)
        ->get(route('dashboard', ['post' => $this->post->ulid]))
        ->assertOk()
        ->assertSee('Working note')
        ->assertSee('Save draft');

    expect($response->getContent())->not->toContain('>Draft<');
});

test('post create route redirects to the dashboard post panel', function () {
    $this->actingAs($this->user)
        ->get(route('dashboard', ['action' => 'new-post', 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('data-test="dashboard-post-create-panel"', escape: false);
});

test('post create route uses the selected topic query as the form default', function () {
    $this->actingAs($this->user)
        ->get(route('dashboard', ['action' => 'new-post', 'topic' => $this->topic->slug, 'panel' => 'posts']))
        ->assertOk()
        ->assertSee('data-test="dashboard-post-create-panel"', escape: false)
        ->assertSee('&quot;newPostTopicId&quot;:'.$this->topic->id, escape: false);
});

test('post page does not resolve topics outside the current workspace', function () {
    $other = Workspace::factory()->for($this->user->currentTeam)->create();
    $otherTopic = Topic::factory()->for($other)->create();
    $otherPost = Post::factory()->for(Thread::factory()->forTopic($otherTopic))->create();

    $this->actingAs($this->user)
        ->get(route('dashboard', ['post' => $otherPost->ulid]))
        ->assertOk()
        ->assertDontSee('id="thread-panel"', escape: false);
});

test('draft post can be saved', function () {
    $this->actingAs($this->user);

    Livewire::test('pages::dashboard')
        ->set('selectedPostUlid', $this->post->ulid)
        ->set('postBody', 'Hello world')
        ->call('saveSelectedPost')
        ->assertHasNoErrors();

    expect($this->post->fresh()->body)->toBe('Hello world');
});

test('published post page shows sender and topic', function () {
    $senderPrincipal = $this->workspace->principalForUser($this->user);
    $postedAt = now()->subMinutes(4);

    $this->post->timestamps = false;
    $this->post->forceFill([
        'status' => PostStatus::Published,
        'sender_principal_id' => $senderPrincipal->id,
        'created_at' => $postedAt,
    ])->save();

    $this->actingAs($this->user)
        ->get(route('dashboard', ['post' => $this->post->ulid]))
        ->assertOk()
        ->assertSee('data-test="post-message"', escape: false)
        ->assertSee('data-test="post-message-sender"', escape: false)
        ->assertSee('data-test="post-message-timestamp"', escape: false)
        ->assertSee(e($postedAt->timezone($this->user->displayTimezone())->isoFormat('LLLL')), escape: false)
        ->assertSee($this->user->name)
        ->assertSee('#'.$this->topic->name)
        ->assertSee('Edit')
        ->assertSee('Delete')
        ->assertDontSee('Archive')
        ->assertDontSee('Move to drafts')
        ->assertDontSee('Return to draft');
});

test('post list metadata uses sender and timestamp labels', function () {
    $senderPrincipal = $this->workspace->principalForUser($this->user);
    $updatedAt = now()->subMinutes(5);

    $this->post->timestamps = false;
    $this->post->forceFill([
        'status' => PostStatus::Published,
        'sender_principal_id' => $senderPrincipal->id,
        'updated_at' => $updatedAt,
    ])->save();

    expect($this->post->fresh()->load('sender.user')->listMeta(
        showSender: true,
    ))->toBe([
        ['key' => 'sender', 'label' => 'Sender', 'value' => $this->user->name],
        ['key' => 'sent', 'label' => 'Sent', 'value' => '5 minutes ago', 'title' => $updatedAt->timezone(config('app.timezone'))->isoFormat('LLLL')],
    ]);
});

test('post list topic metadata uses stable keys', function () {
    $senderPrincipal = $this->workspace->principalForUser($this->user);
    $updatedAt = now()->subMinutes(8);

    $this->post->timestamps = false;
    $this->post->forceFill([
        'status' => PostStatus::Published,
        'sender_principal_id' => $senderPrincipal->id,
        'updated_at' => $updatedAt,
    ])->save();

    expect($this->post->fresh()->load(['sender.user', 'topic'])->listTopicMeta(
        showSender: true,
    ))->toBe([
        ['key' => 'sender', 'label' => 'Sender', 'value' => $this->user->name],
        ['key' => 'topic', 'label' => 'Topic', 'value' => $this->topic->name],
        ['key' => 'sent', 'label' => 'Sent', 'value' => '8 minutes ago', 'title' => $updatedAt->timezone(config('app.timezone'))->isoFormat('LLLL')],
    ]);
});

test('draft post topic metadata hides sender', function () {
    $updatedAt = now()->subMinutes(3);

    $this->post->timestamps = false;
    $this->post->forceFill([
        'status' => PostStatus::Draft,
        'updated_at' => $updatedAt,
    ])->save();

    expect($this->post->fresh()->load('topic')->listTopicMeta(
        showSender: false,
    ))->toBe([
        ['key' => 'topic', 'label' => 'Topic', 'value' => $this->topic->name],
        ['key' => 'saved', 'label' => 'Saved', 'value' => '3 minutes ago', 'title' => $updatedAt->timezone(config('app.timezone'))->isoFormat('LLLL')],
    ]);
});

test('post list timestamp titles use the user timezone when provided', function () {
    $updatedAt = now()->setTimezone('UTC')->setTime(12, 0);

    Date::setTestNow($updatedAt);

    try {
        $this->post->timestamps = false;
        $this->post->forceFill([
            'status' => PostStatus::Published,
            'updated_at' => $updatedAt,
        ])->save();

        expect($this->post->fresh()->listMeta(
            showSender: false,
            timezone: 'Africa/Johannesburg',
        ))->toBe([
            [
                'key' => 'sent',
                'label' => 'Sent',
                'value' => '0 seconds ago',
                'title' => $updatedAt->copy()->timezone('Africa/Johannesburg')->isoFormat('LLLL'),
            ],
        ]);
    } finally {
        Date::setTestNow();
    }
});

test('post list sort values are normalized for deterministic column sorting', function () {
    $senderPrincipal = $this->workspace->principalForUser($this->user);
    Attachment::factory()->count(2)->for($this->post)->create();

    $this->post->timestamps = false;
    $this->post->forceFill([
        'body' => 'Mixed Case Title',
        'status' => PostStatus::Draft,
        'sender_principal_id' => $senderPrincipal->id,
        'updated_at' => now()->setTimestamp(123),
    ])->save();

    expect($this->post->fresh()->loadCount('attachments')->load('sender.user')->listSortValues())->toMatchArray([
        'post' => 'mixed case title',
        'sender' => str($this->user->name)->lower()->toString(),
        'saved' => '00000000000000000123',
        'attachments' => '0000000002',
        'status' => 'draft',
    ]);
});

test('published post cannot be saved', function () {
    $this->post->update(['status' => PostStatus::Published]);

    $this->actingAs($this->user);

    Livewire::test('pages::dashboard')
        ->set('selectedPostUlid', $this->post->ulid)
        ->set('postBody', 'Hello world')
        ->call('saveSelectedPost')
        ->assertForbidden();
});

test('attachments are saved with a draft post', function () {
    $this->actingAs($this->user);

    $file = UploadedFile::fake()->create('report.pdf', 512, 'application/pdf');

    Livewire::test('pages::dashboard')
        ->set('selectedPostUlid', $this->post->ulid)
        ->set('postUploads', [$file])
        ->set('postBody', 'Attach this')
        ->call('saveSelectedPost')
        ->assertHasNoErrors();

    $attachment = $this->post->attachments()->first();

    expect($this->post->attachments()->count())->toBe(1)
        ->and($attachment->filename)->toBe('report.pdf')
        ->and($this->workspace->filesystem()->exists($attachment->path))->toBeTrue();

    $this->workspace->filesystem()->delete('attachments');
});

test('draft post publishes as a topic post', function () {
    $this->actingAs($this->user);

    $senderPrincipal = $this->workspace->principalForUser($this->user);

    Livewire::test('pages::dashboard')
        ->set('selectedPostUlid', $this->post->ulid)
        ->set('postBody', 'Topic post')
        ->call('publishSelectedPost')
        ->assertHasNoErrors();

    expect($this->post->fresh())
        ->body->toBe('Topic post')
        ->sender_principal_id->toBe($senderPrincipal->id)
        ->status->toBe(PostStatus::Published);
});

test('a topic has many posts', function () {
    Post::factory()->count(2)->for(Thread::factory()->forTopic($this->topic))->create();

    expect($this->topic->posts()->count())->toBe(3); // 1 from beforeEach + 2
});

test('a post has many attachments', function () {
    Attachment::factory()->count(2)->for($this->post)->create();

    expect($this->post->attachments()->count())->toBe(2);
});

test('attachments are soft deleted', function () {
    $attachment = Attachment::factory()->for($this->post)->create();
    $attachment->delete();

    expect(Attachment::withTrashed()->find($attachment->id))->not->toBeNull();
    expect(Attachment::find($attachment->id))->toBeNull();
});
