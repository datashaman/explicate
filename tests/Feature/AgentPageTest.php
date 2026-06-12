<?php

use App\Enums\Provider;
use App\Enums\ReasoningEffort;
use App\Models\Agent;
use App\Models\AgentVersion;
use App\Models\ProviderKey;
use App\Models\Topic;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'ai.providers.anthropic.key' => null,
        'ai.providers.gemini.key' => null,
        'ai.providers.openai.key' => null,
        'ai.providers.groq.key' => null,
    ]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->for($this->user->currentTeam)->create();
    $this->user->switchWorkspace($this->workspace);

    foreach ([Provider::Anthropic, Provider::OpenAI] as $provider) {
        ProviderKey::create([
            'team_id' => $this->user->currentTeam->id,
            'workspace_id' => null,
            'provider' => $provider,
            'api_key' => 'test-'.$provider->value.'-key',
        ]);
    }
});

test('agents page loads', function () {
    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertOk();
});

test('agent detail page uses main and sidebar layout', function () {
    $agent = Agent::factory()->for($this->workspace)->create();

    $this->actingAs($this->user)
        ->get(route('dashboard', ['agent' => $agent->slug]))
        ->assertOk()
        ->assertSee('data-test="workspace-agent-row-'.$agent->slug.'"', escape: false)
        ->assertSee($agent->name);
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
        ->get(route('dashboard', ['agent' => $currentAgent->slug]))
        ->assertOk()
        ->assertSee('Current Agent')
        ->assertDontSee('Other Agent');
});

test('agents page shows workspace agents', function () {
    $agent = Agent::factory()->for($this->workspace)->create();

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee($agent->name);
});

test('agent can be created', function () {
    $this->actingAs($this->user);

    Livewire::test('pages::dashboard')
        ->set('agentName', 'My Agent')
        ->set('provider', Provider::OpenAI->value)
        ->set('model', 'gpt-5.5')
        ->set('reasoningEffort', ReasoningEffort::Medium->value)
        ->set('prompt', 'Be helpful.')
        ->set('allowedTools', ['get-thread', 'write-file'])
        ->call('createAgent')
        ->assertHasNoErrors();

    $agent = $this->workspace->agents()->where('name', 'My Agent')->first();

    expect($agent)->not->toBeNull();
    expect($agent->versions)->toHaveCount(1);
    expect($agent->versions->first()->provider)->toBe(Provider::OpenAI);
    expect($agent->versions->first()->model)->toBe('gpt-5.5');
    expect($agent->versions->first()->reasoning_effort)->toBe(ReasoningEffort::Medium);
    expect($agent->versions->first()->prompt)->toBe('Be helpful.');
    expect($agent->versions->first()->allowed_tools)->toBe(['get-thread', 'write-file']);
});

test('agent cannot be created for a provider without a configured key', function () {
    $this->actingAs($this->user);

    Livewire::test('pages::dashboard')
        ->set('agentName', 'No Key Agent')
        ->set('provider', Provider::Gemini->value)
        ->set('model', 'gemini-2.5-pro')
        ->call('createAgent')
        ->assertHasErrors(['provider']);

    expect($this->workspace->agents()->where('name', 'No Key Agent')->exists())->toBeFalse();
});

test('agent version clears reasoning effort when the model does not support it', function () {
    $agent = Agent::factory()->for($this->workspace)->create([
        'name' => 'Legacy Agent',
        'slug' => 'legacy-agent',
    ]);
    AgentVersion::factory()->for($agent)->create([
        'provider' => Provider::OpenAI,
        'model' => 'gpt-5.5',
        'reasoning_effort' => ReasoningEffort::Medium,
    ]);

    $this->actingAs($this->user);

    Livewire::test('pages::dashboard')
        ->set('selectedAgentSlug', 'legacy-agent')
        ->set('selectedAgentProvider', Provider::OpenAI->value)
        ->set('selectedAgentModel', 'gpt-4.1')
        ->set('selectedAgentReasoningEffort', ReasoningEffort::High->value)
        ->call('saveSelectedAgentVersion')
        ->assertHasNoErrors();

    $version = $agent->fresh()->latestVersion;

    expect($version?->model)->toBe('gpt-4.1')
        ->and($version?->reasoning_effort)->toBeNull();
});

test('agent form shows and saves allowed tools on new versions', function () {
    $agent = Agent::factory()->for($this->workspace)->create([
        'name' => 'Tool Agent',
        'slug' => 'tool-agent',
    ]);
    AgentVersion::factory()->for($agent)->create([
        'provider' => Provider::Anthropic,
        'model' => 'claude-sonnet-4-6',
        'allowed_tools' => ['get-thread'],
    ]);

    $this->actingAs($this->user)
        ->get(route('dashboard', ['agent' => 'tool-agent']))
        ->assertOk()
        ->assertSee('data-test="selected-agent-tool-matrix"', false)
        ->assertSee('get-thread')
        ->assertSee('write-file');

    Livewire::actingAs($this->user)
        ->test('pages::dashboard')
        ->set('selectedAgentSlug', 'tool-agent')
        ->assertSet('selectedAgentAllowedTools', ['get-thread'])
        ->set('selectedAgentProvider', Provider::OpenAI->value)
        ->set('selectedAgentModel', 'gpt-5.5')
        ->set('selectedAgentReasoningEffort', ReasoningEffort::Low->value)
        ->set('selectedAgentPrompt', 'Use only selected tools.')
        ->set('selectedAgentAllowedTools', ['get-thread', 'write-file'])
        ->call('saveSelectedAgentVersion')
        ->assertHasNoErrors();

    $latest = $agent->fresh()->latestVersion;

    expect($latest?->version)->toBe(2)
        ->and($latest?->allowed_tools)->toBe(['get-thread', 'write-file']);
});

test('agent version cannot be created for a provider without a configured key', function () {
    $agent = Agent::factory()->for($this->workspace)->create([
        'name' => 'Tool Agent',
        'slug' => 'tool-agent',
    ]);
    AgentVersion::factory()->for($agent)->create([
        'provider' => Provider::Anthropic,
        'model' => 'claude-sonnet-4-6',
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::dashboard')
        ->set('selectedAgentSlug', 'tool-agent')
        ->set('selectedAgentProvider', Provider::Gemini->value)
        ->set('selectedAgentModel', 'gemini-2.5-pro')
        ->call('saveSelectedAgentVersion')
        ->assertHasErrors(['selectedAgentProvider']);

    expect($agent->versions()->count())->toBe(1);
});

test('agent can be deleted', function () {
    $agent = Agent::factory()->for($this->workspace)->create();

    $this->actingAs($this->user);

    Livewire::test('pages::dashboard')
        ->call('deleteAgent', $agent->id)
        ->assertHasNoErrors();

    expect(Agent::find($agent->id))->toBeNull();
});

test('agent details can be updated', function () {
    $agent = Agent::factory()->for($this->workspace)->create();

    $this->actingAs($this->user);

    Livewire::test('pages::dashboard')
        ->set('selectedAgentSlug', $agent->slug)
        ->set('selectedAgentName', 'Renamed Agent')
        ->call('saveSelectedAgentDetails')
        ->assertHasNoErrors();

    expect($agent->fresh()->name)->toBe('Renamed Agent');
});

test('topic page can create a workspace agent with first version details', function () {
    $topic = Topic::factory()->for($this->workspace)->create();

    $this->actingAs($this->user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('agentName', 'Topic Agent')
        ->set('provider', Provider::Anthropic->value)
        ->set('model', 'claude-sonnet-4-6')
        ->set('prompt', 'Stay focused.')
        ->call('createAgent')
        ->assertHasNoErrors();

    $agent = $this->workspace->agents()->where('name', 'Topic Agent')->first();

    expect($agent)->not->toBeNull();
    expect($agent->versions)->toHaveCount(1);
    expect($agent->versions->first()->model)->toBe('claude-sonnet-4-6');
});
