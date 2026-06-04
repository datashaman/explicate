<?php

use App\Models\Agent;
use App\Models\Topic;
use App\Models\Workspace;
use Illuminate\Database\QueryException;

test('a workspace has many agents', function () {
    $workspace = Workspace::factory()->create();
    Agent::factory()->count(3)->for($workspace)->create();

    expect($workspace->agents)->toHaveCount(3);
});

test('agents are ordered by name', function () {
    $workspace = Workspace::factory()->create();
    Agent::factory()->for($workspace)->create(['name' => 'Zebra', 'slug' => 'zebra']);
    Agent::factory()->for($workspace)->create(['name' => 'Alpha', 'slug' => 'alpha']);

    expect($workspace->agents->first()->name)->toBe('Alpha');
    expect($workspace->agents->last()->name)->toBe('Zebra');
});

test('an agent belongs to a workspace', function () {
    $agent = Agent::factory()->create();

    expect($agent->workspace)->toBeInstanceOf(Workspace::class);
});

test('agent slug is unique per workspace', function () {
    $workspace = Workspace::factory()->create();
    Agent::factory()->for($workspace)->create(['name' => 'My Agent', 'slug' => 'my-agent']);

    expect(fn () => Agent::factory()->for($workspace)->create(['name' => 'My Agent', 'slug' => 'my-agent']))
        ->toThrow(QueryException::class);
});

test('agents are soft deleted', function () {
    $agent = Agent::factory()->create();
    $agent->delete();

    expect(Agent::withTrashed()->find($agent->id))->not->toBeNull();
    expect(Agent::find($agent->id))->toBeNull();
});

test('an agent can be assigned to a topic', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->for($workspace)->create();
    $topic = Topic::factory()->for($workspace)->create();

    $topic->agents()->attach($agent);

    expect($topic->agents)->toHaveCount(1);
    expect($topic->agents->first()->id)->toBe($agent->id);
});

test('a topic can have multiple agents assigned', function () {
    $workspace = Workspace::factory()->create();
    $topic = Topic::factory()->for($workspace)->create();
    $agents = Agent::factory()->count(3)->for($workspace)->create();

    $topic->agents()->attach($agents->pluck('id'));

    expect($topic->agents)->toHaveCount(3);
});

test('an agent can be assigned to multiple topics', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->for($workspace)->create();
    $topics = Topic::factory()->count(2)->for($workspace)->create();

    $topics->each(fn ($topic) => $topic->agents()->attach($agent));

    expect($agent->topics)->toHaveCount(2);
});
