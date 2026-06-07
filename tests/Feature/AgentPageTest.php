<?php

use App\Enums\Provider;
use App\Enums\ReasoningEffort;
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

test('agent detail page uses main and sidebar layout', function () {
    $agent = Agent::factory()->for($this->workspace)->create();

    $this->actingAs($this->user)
        ->get(route('agents.show', ['agent' => $agent->slug]))
        ->assertOk()
        ->assertSee('xl:grid-cols-[minmax(0,1.7fr)_22rem]', escape: false);
});

test('agent routes resolve slugs inside the current workspace', function () {
    $otherWorkspace = Workspace::factory()->for($this->user->currentTeam)->create();

    Agent::factory()->for($otherWorkspace)->create([
        'name' => 'Other Agent',
        'slug' => 'shared-agent',
    ]);

    $currentAgent = Agent::factory()->for($this->workspace)->create([
        'name' => 'Current Agent',
        'slug' => 'shared-agent',
    ]);

    $this->actingAs($this->user)
        ->get(route('agents.show', ['agent' => $currentAgent->slug]))
        ->assertOk()
        ->assertSee('Current Agent')
        ->assertDontSee('Other Agent');
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
        ->set('provider', Provider::OpenAI->value)
        ->set('model', 'o4-mini')
        ->set('reasoningEffort', ReasoningEffort::Medium->value)
        ->set('prompt', 'Be helpful.')
        ->call('createAgent')
        ->assertHasNoErrors();

    $agent = $this->workspace->agents()->where('name', 'My Agent')->first();

    expect($agent)->not->toBeNull();
    expect($agent->versions)->toHaveCount(1);
    expect($agent->versions->first()->provider)->toBe(Provider::OpenAI);
    expect($agent->versions->first()->model)->toBe('o4-mini');
    expect($agent->versions->first()->reasoning_effort)->toBe(ReasoningEffort::Medium);
    expect($agent->versions->first()->prompt)->toBe('Be helpful.');
});

test('agent can be deleted', function () {
    $agent = Agent::factory()->for($this->workspace)->create();

    $this->actingAs($this->user);

    Livewire::test('pages::agents')
        ->call('deleteAgent', $agent->id)
        ->assertHasNoErrors();

    expect(Agent::find($agent->id))->toBeNull();
});

test('agent details can be updated', function () {
    $agent = Agent::factory()->for($this->workspace)->create();

    $this->actingAs($this->user);

    Livewire::test('pages::agent', ['agent' => $agent])
        ->set('agentName', 'Renamed Agent')
        ->call('saveDetails')
        ->assertHasNoErrors();

    expect($agent->fresh()->name)->toBe('Renamed Agent');
});

test('topic page can create a workspace agent with first version details', function () {
    $topic = Topic::factory()->for($this->workspace)->create();

    $this->actingAs($this->user);

    Livewire::test('pages::topic', ['topic' => $topic])
        ->set('agentName', 'Topic Agent')
        ->set('agentProvider', Provider::Anthropic->value)
        ->set('agentModel', 'claude-sonnet-4-6')
        ->set('agentPrompt', 'Stay focused.')
        ->call('createAgent')
        ->assertHasNoErrors();

    $agent = $this->workspace->agents()->where('name', 'Topic Agent')->first();

    expect($agent)->not->toBeNull();
    expect($agent->versions)->toHaveCount(1);
    expect($agent->versions->first()->model)->toBe('claude-sonnet-4-6');
});
