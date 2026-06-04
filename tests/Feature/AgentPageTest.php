<?php

use App\Models\Agent;
use App\Models\Topic;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->for($this->user->currentTeam)->create();
    $this->user->switchWorkspace($this->workspace);
});

test('agents page loads', function () {
    $this->actingAs($this->user)
        ->get(route('agents'))
        ->assertOk();
});

test('agents page shows workspace agents', function () {
    $agent = Agent::factory()->for($this->workspace)->create();

    $this->actingAs($this->user)
        ->get(route('agents'))
        ->assertOk()
        ->assertSee($agent->name);
});

test('agent can be created', function () {
    $this->actingAs($this->user);

    Livewire::test('pages::agents')
        ->set('agentName', 'My Agent')
        ->call('createAgent')
        ->assertHasNoErrors();

    expect($this->workspace->agents()->where('name', 'My Agent')->exists())->toBeTrue();
});

test('agent can be deleted', function () {
    $agent = Agent::factory()->for($this->workspace)->create();

    $this->actingAs($this->user);

    Livewire::test('pages::agents')
        ->call('deleteAgent', $agent->id)
        ->assertHasNoErrors();

    expect(Agent::find($agent->id))->toBeNull();
});

test('agent can be assigned to a topic', function () {
    $agent = Agent::factory()->for($this->workspace)->create();
    $topic = Topic::factory()->for($this->workspace)->create();

    $this->actingAs($this->user);

    Livewire::test('pages::topic', ['topic' => $topic])
        ->set('assignAgentId', $agent->id)
        ->call('assignAgent')
        ->assertHasNoErrors();

    expect($topic->agents()->where('agents.id', $agent->id)->exists())->toBeTrue();
});

test('agent can be unassigned from a topic', function () {
    $agent = Agent::factory()->for($this->workspace)->create();
    $topic = Topic::factory()->for($this->workspace)->create();
    $topic->agents()->attach($agent);

    $this->actingAs($this->user);

    Livewire::test('pages::topic', ['topic' => $topic])
        ->call('unassignAgent', $agent->id)
        ->assertHasNoErrors();

    expect($topic->agents()->where('agents.id', $agent->id)->exists())->toBeFalse();
});

test('available agents excludes already assigned agents', function () {
    $assigned = Agent::factory()->for($this->workspace)->create();
    $available = Agent::factory()->for($this->workspace)->create();
    $topic = Topic::factory()->for($this->workspace)->create();
    $topic->agents()->attach($assigned);

    $this->actingAs($this->user);

    $component = Livewire::test('pages::topic', ['topic' => $topic]);

    $ids = $component->instance()->availableAgents()->pluck('id');

    expect($ids)->toContain($available->id)
        ->not->toContain($assigned->id);
});
