<?php

use App\Models\Message;
use App\Models\Topic;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;

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
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee($topic->name);
});

test('topic page left aligns message icons in icon view', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create();
    Message::factory()->for($topic)->create();

    $this->actingAs($user)
        ->get(route('topics.show', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertSee('x-if="view === \'icons\'"', escape: false)
        ->assertSee('x-if="view === \'list\'"', escape: false);
});
