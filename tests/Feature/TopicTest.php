<?php

use App\Enums\Provider;
use App\Enums\ReasoningEffort;
use App\Models\Agent;
use App\Models\Message;
use App\Models\Topic;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
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
    $message = Message::factory()->for($topic)->create(['title' => 'Dashboard draft']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Topics')
        ->assertSee('Messages')
        ->assertSee($topic->name)
        ->assertSee($message->title);
});

test('dashboard shows selected topic in the main panel', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

    $selectedTopic = Topic::factory()->for($workspace)->create(['name' => 'Selected Topic', 'slug' => 'selected-topic']);
    $otherTopic = Topic::factory()->for($workspace)->create(['name' => 'Other Topic', 'slug' => 'other-topic']);

    $selectedMessage = Message::factory()->for($selectedTopic)->create(['title' => 'Selected message']);
    Message::factory()->for($otherTopic)->create(['title' => 'Other message']);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $selectedTopic->slug]))
        ->assertOk()
        ->assertSee('Selected Topic')
        ->assertSee($selectedMessage->title)
        ->assertDontSee('Other message');
});

test('dashboard shows workspace agents in the right rail', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

    $agent = Agent::factory()->for($workspace)->create(['name' => 'Rail Agent']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Workspace agents')
        ->assertSee('New agent')
        ->assertSee($agent->name);
});

test('dashboard can create an agent from the right rail', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

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

test('dashboard shows new message action', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

    Topic::factory()->for($workspace)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('messages.create'), escape: false);
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
        ->assertSee('Workspace agents')
        ->assertSee('x-if="view === \'icons\'"', escape: false)
        ->assertSee('x-if="view === \'list\'"', escape: false);
});

test('topic page agent rail labels attach and detach actions clearly', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

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
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create();
    Agent::factory()->for($workspace)->create(['name' => 'Available Agent']);

    $this->actingAs($user)
        ->get(route('topics.show', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertSee('Attach')
        ->assertDontSee('Detach this agent from the topic?');
});
