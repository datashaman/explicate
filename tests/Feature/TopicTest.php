<?php

use App\Enums\MessageStatus;
use App\Enums\Provider;
use App\Enums\ReasoningEffort;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\AgentVersion;
use App\Models\Attachment;
use App\Models\Message;
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

    $topic = Topic::factory()->for($workspace)->create();
    $message = Message::factory()->for($topic)->create(['title' => 'Dashboard draft']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Topics')
        ->assertSee('Messages')
        ->assertSee('Inbox')
        ->assertSee('Draft')
        ->assertSee('Sent')
        ->assertSee($topic->name)
        ->assertDontSee($message->title)
        ->assertSee('Select a topic')
        ->assertSee('Choose a topic to view its messages.');
});

test('dashboard shows system folders with workspace message counts', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $userPrincipal = $workspace->principalForUser($user);
    Message::factory()->for($topic)->create(['status' => MessageStatus::Draft]);
    Message::factory()->for($topic)->create([
        'status' => MessageStatus::Published,
        'sender_principal_id' => $userPrincipal->id,
    ]);
    Message::factory()->for($topic)->create([
        'status' => MessageStatus::Published,
        'sender_principal_id' => $userPrincipal->id,
        'recipient_principal_id' => $userPrincipal->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(e(route('dashboard', ['folder' => 'inbox', 'panel' => 'messages'])), escape: false)
        ->assertSee(e(route('dashboard', ['folder' => 'draft', 'panel' => 'messages'])), escape: false)
        ->assertSee(e(route('dashboard', ['folder' => 'sent', 'panel' => 'messages'])), escape: false)
        ->assertSee('data-test="system-folder-inbox-count"', escape: false)
        ->assertSee('data-test="system-folder-draft-count"', escape: false)
        ->assertSee('data-test="system-folder-sent-count"', escape: false);
});

test('dashboard system draft folder shows draft messages across topics', function () {
    [$user, $workspace] = userWithWorkspace();

    $design = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $engineering = Topic::factory()->for($workspace)->create(['slug' => 'engineering']);
    $userPrincipal = $workspace->principalForUser($user);

    $designDraft = Message::factory()->for($design)->create([
        'title' => 'Design draft',
        'updated_at' => now()->subMinutes(7),
        'status' => MessageStatus::Draft,
    ]);

    Message::factory()->for($engineering)->create([
        'title' => 'Engineering sent',
        'status' => MessageStatus::Published,
        'sender_principal_id' => $userPrincipal->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['folder' => 'draft', 'panel' => 'messages']))
        ->assertOk()
        ->assertSee('Draft')
        ->assertSee('Design draft')
        ->assertSee(e(route('dashboard', [
            'folder' => 'draft',
            'topic' => $design->slug,
            'message' => $designDraft->slug,
            'panel' => 'messages',
        ])), escape: false)
        ->assertSeeText('Saved:')
        ->assertSeeText('7 minutes ago')
        ->assertSee('data-test="folder-list-sort-saved"', escape: false)
        ->assertSee('data-sort-saved=', escape: false)
        ->assertDontSee('data-test="folder-list-sort-sent"', escape: false)
        ->assertDontSee('Engineering sent');
});

test('dashboard inbox does not show draft messages', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $userPrincipal = $workspace->principalForUser($user);

    Message::factory()->for($topic)->create([
        'title' => 'Hidden draft',
        'status' => MessageStatus::Draft,
    ]);

    Message::factory()->for($topic)->create([
        'title' => 'Visible message',
        'status' => MessageStatus::Published,
        'sender_principal_id' => $userPrincipal->id,
        'recipient_principal_id' => $userPrincipal->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['folder' => 'inbox', 'panel' => 'messages']))
        ->assertOk()
        ->assertSee('Visible message')
        ->assertDontSee('Hidden draft');
});

test('dashboard inbox only shows messages addressed to the current user', function () {
    [$user, $workspace] = userWithWorkspace();
    [$recipient, $recipientPrincipal] = teamMemberPrincipal($user, $workspace);

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $userPrincipal = $workspace->principalForUser($user);

    Message::factory()->for($topic)->create([
        'title' => 'For me',
        'updated_at' => now()->subMinutes(9),
        'status' => MessageStatus::Published,
        'sender_principal_id' => $recipientPrincipal->id,
        'recipient_principal_id' => $userPrincipal->id,
    ]);

    Message::factory()->for($topic)->create([
        'title' => 'For someone else',
        'status' => MessageStatus::Published,
        'sender_principal_id' => $userPrincipal->id,
        'recipient_principal_id' => $recipientPrincipal->id,
    ]);

    Message::factory()->for($topic)->create([
        'title' => 'For the topic',
        'status' => MessageStatus::Published,
        'sender_principal_id' => $userPrincipal->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['folder' => 'inbox', 'panel' => 'messages']))
        ->assertOk()
        ->assertSee('For me')
        ->assertSeeText('From:')
        ->assertSeeText($recipient->name)
        ->assertSeeText('Sent:')
        ->assertSeeText('9 minutes ago')
        ->assertDontSeeText('To:')
        ->assertDontSee('For someone else')
        ->assertDontSee('For the topic');
});

test('dashboard sent folder shows recipients and sent time', function () {
    [$user, $workspace] = userWithWorkspace();
    [, $recipientPrincipal] = teamMemberPrincipal($user, $workspace, ['name' => 'Message Recipient']);

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $userPrincipal = $workspace->principalForUser($user);

    Message::factory()->for($topic)->create([
        'title' => 'Sent direct message',
        'updated_at' => now()->subMinutes(11),
        'status' => MessageStatus::Published,
        'sender_principal_id' => $userPrincipal->id,
        'recipient_principal_id' => $recipientPrincipal->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['folder' => 'sent', 'panel' => 'messages']))
        ->assertOk()
        ->assertSee('Sent direct message')
        ->assertSeeText('To:')
        ->assertSeeText('Message Recipient')
        ->assertSeeText('Sent:')
        ->assertSeeText('11 minutes ago')
        ->assertDontSeeText('From:');
});

test('dashboard archived toggle only filters the selected messages list', function () {
    [$user, $workspace] = userWithWorkspace();

    $design = Topic::factory()->for($workspace)->create([
        'name' => 'Design',
        'slug' => 'design',
    ]);

    $engineering = Topic::factory()->for($workspace)->create([
        'name' => 'Engineering',
        'slug' => 'engineering',
    ]);

    Message::factory()->for($design)->create([
        'title' => 'Design draft',
        'status' => MessageStatus::Draft,
    ]);

    Message::factory()->for($design)->create([
        'title' => 'Design published',
        'status' => MessageStatus::Published,
    ]);

    Message::factory()->for($design)->create([
        'title' => 'Design archived',
        'status' => MessageStatus::Archived,
    ]);

    Message::factory()->count(9)->for($engineering)->create([
        'status' => MessageStatus::Archived,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $design->slug]))
        ->assertOk()
        ->assertDontSee('Design draft')
        ->assertDontSee('data-test="topic-design-draft-count"', escape: false)
        ->assertDontSee('title="Draft messages"', escape: false)
        ->assertSee('data-test="topic-design-published-count"', escape: false)
        ->assertSee('title="Messages"', escape: false)
        ->assertDontSee('Design archived')
        ->assertDontSee('data-test="topic-design-archived-count"', escape: false)
        ->assertDontSee('data-test="topic-engineering-archived-count"', escape: false);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->set('selectedTopicSlug', $design->slug)
        ->set('showArchived', true)
        ->assertSee('Design archived')
        ->assertSee('data-test="topic-design-archived-count"', escape: false)
        ->assertSee('title="Archived messages"', escape: false)
        ->assertSee('data-count="1"', escape: false)
        ->assertDontSee('data-test="topic-engineering-archived-count"', escape: false);
});

test('dashboard routes do not include team or workspace slugs', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'current-topic']);

    expect(route('dashboard', absolute: false))->toBe('/dashboard')
        ->and(route('topics.show', ['topic' => $topic->slug], false))->toBe('/topics/current-topic')
        ->and(route('messages.show', ['message' => 'current-message'], false))->toBe('/messages/current-message')
        ->and(route('messages.create', ['topic' => $topic->slug], false))->toBe('/messages/new?topic=current-topic');
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

    $selectedMessage = Message::factory()->for($selectedTopic)->create([
        'title' => 'Selected message',
        'status' => MessageStatus::Published,
    ]);
    Message::factory()->for($otherTopic)->create(['title' => 'Other message']);
    Message::factory()->for($selectedTopic)->create([
        'title' => 'Direct message',
        'status' => MessageStatus::Published,
        'recipient_principal_id' => $workspace->principalForUser($user)->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $selectedTopic->slug]))
        ->assertOk()
        ->assertSee('Selected Topic')
        ->assertSee($selectedMessage->title)
        ->assertSee(e(route('dashboard', ['topic' => $selectedTopic->slug, 'message' => $selectedMessage->slug, 'panel' => 'messages'])), escape: false)
        ->assertDontSee(route('messages.show', ['message' => $selectedMessage]), escape: false)
        ->assertDontSee('Direct message')
        ->assertDontSee('Other message');
});

test('dashboard shows selected draft message in the main panel', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);
    $message = Message::factory()->for($topic)->create([
        'title' => 'Draft brief',
        'body' => 'Draft body',
        'status' => MessageStatus::Draft,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug, 'message' => $message->slug, 'panel' => 'messages']))
        ->assertOk()
        ->assertSee('data-test="dashboard-message-panel"', escape: false)
        ->assertSee('Draft brief')
        ->assertSee('Draft body')
        ->assertDontSee('data-flux-breadcrumbs', escape: false)
        ->assertSee('Save draft')
        ->assertSee('Send');
});

test('dashboard can save selected draft message', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $message = Message::factory()->for($topic)->create([
        'title' => 'Draft brief',
        'body' => 'Draft body',
        'status' => MessageStatus::Draft,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('selectedMessageSlug', $message->slug)
        ->set('messageTitle', 'Updated brief')
        ->set('messageBody', 'Updated body')
        ->call('saveSelectedMessage')
        ->assertHasNoErrors();

    expect($message->fresh())
        ->title->toBe('Updated brief')
        ->body->toBe('Updated body');
});

test('dashboard can change a draft message recipient to an agent principal', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $agent = Agent::factory()->for($workspace)->create(['name' => 'Researcher']);
    $agentPrincipal = $workspace->principalForAgent($agent);
    $message = Message::factory()->for($topic)->create([
        'title' => 'Draft note',
        'status' => MessageStatus::Draft,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('selectedMessageSlug', $message->slug)
        ->set('messageTitle', 'Agent draft')
        ->set('messageTarget', 'principal')
        ->set('messageRecipientPrincipalId', $agentPrincipal->id)
        ->call('saveSelectedMessage')
        ->assertHasNoErrors();

    expect($message->fresh())
        ->title->toBe('Agent draft')
        ->recipient_principal_id->toBe($agentPrincipal->id);
});

test('dashboard published message panel shows sender and recipient principals', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $agent = Agent::factory()->for($workspace)->create(['name' => 'Researcher']);
    $message = Message::factory()->for($topic)->create([
        'title' => 'Published note',
        'status' => MessageStatus::Published,
        'sender_principal_id' => $workspace->principalForUser($user)->id,
        'recipient_principal_id' => $workspace->principalForAgent($agent)->id,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug, 'message' => $message->slug, 'panel' => 'messages']))
        ->assertOk()
        ->assertSee('From')
        ->assertSee($user->name)
        ->assertSee('To')
        ->assertSee('Researcher');
});

test('dashboard message panel shows attachments', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $message = Message::factory()->for($topic)->create([
        'title' => 'Published note',
        'status' => MessageStatus::Published,
    ]);
    Attachment::factory()->for($message)->create([
        'filename' => 'roadmap.pdf',
        'size' => 2048,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug, 'message' => $message->slug, 'panel' => 'messages']))
        ->assertOk()
        ->assertSee('Attachments')
        ->assertSee('roadmap.pdf')
        ->assertSee('2 KB')
        ->assertDontSee('wire:submit="uploadSelectedMessageAttachments"', escape: false);
});

test('dashboard can upload attachments to selected draft message', function () {
    Storage::fake('public');

    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $message = Message::factory()->for($topic)->create([
        'title' => 'Draft brief',
        'status' => MessageStatus::Draft,
    ]);
    $file = UploadedFile::fake()->create('brief.pdf', 128, 'application/pdf');

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('selectedMessageSlug', $message->slug)
        ->set('messageUploads', [$file])
        ->call('uploadSelectedMessageAttachments')
        ->assertHasNoErrors()
        ->assertSet('messageUploads', []);

    expect($message->attachments()->count())->toBe(1);
    expect($message->attachments()->first()->filename)->toBe('brief.pdf');
});

test('dashboard can delete attachments from selected draft message', function () {
    Storage::fake('public');

    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $message = Message::factory()->for($topic)->create([
        'title' => 'Draft brief',
        'status' => MessageStatus::Draft,
    ]);
    $attachment = Attachment::factory()->for($message)->create([
        'path' => 'attachments/report.pdf',
    ]);

    Storage::disk('public')->put($attachment->path, 'report');

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('selectedMessageSlug', $message->slug)
        ->call('deleteSelectedMessageAttachment', $attachment->id)
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

test('dashboard shows new message action', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);

    $this->actingAs($user)
        ->get(route('dashboard', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertSee(e(route('messages.create', ['topic' => $topic->slug])), escape: false)
        ->assertDontSee(e(route('dashboard', ['topic' => $topic->slug, 'action' => 'new-message', 'panel' => 'messages'])), escape: false);
});

test('dashboard shows new message form in the main panel', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);

    $this->actingAs($user)
        ->get(route('messages.create', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertSee('data-test="dashboard-message-create-panel"', escape: false)
        ->assertSee('id="dashboard-new-message-form"', escape: false)
        ->assertSee('form="dashboard-new-message-form"', escape: false)
        ->assertSee('New message')
        ->assertSee('Save draft')
        ->assertSee('Send')
        ->assertSee('Design');
});

test('dashboard shows new message form in the message panel without a selected topic', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['name' => 'Design', 'slug' => 'design']);

    $this->actingAs($user)
        ->get(route('messages.create'))
        ->assertOk()
        ->assertSee('data-test="dashboard-message-create-panel"', escape: false)
        ->assertSee('id="dashboard-new-message-form"', escape: false)
        ->assertSee('form="dashboard-new-message-form"', escape: false)
        ->assertSee('New message')
        ->assertSee('Save draft')
        ->assertSee('Send')
        ->assertSee($topic->name);
});

test('dashboard keeps route-based new message form open while attachments upload', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $file = UploadedFile::fake()->create('brief.pdf', 128, 'application/pdf');

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('creatingMessageFromRoute', true)
        ->set('selectedTopicSlug', $topic->slug)
        ->set('newMessageUploads', [$file])
        ->assertSet('creatingMessageFromRoute', true)
        ->assertSee('id="dashboard-new-message-form"', escape: false)
        ->assertSee('form="dashboard-new-message-form"', escape: false);
});

test('dashboard can create a draft message in the main panel', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('panelAction', 'new-message')
        ->set('newMessageTitle', 'New draft')
        ->set('newMessageBody', 'Draft body')
        ->set('newMessageTopicId', $topic->id)
        ->call('createDashboardMessage')
        ->assertHasNoErrors()
        ->assertSet('panelAction', null)
        ->assertSet('selectedTopicSlug', $topic->slug)
        ->assertSet('selectedMessageSlug', 'new-draft');

    $message = $topic->messages()->where('title', 'New draft')->first();

    expect($message)->not->toBeNull()
        ->and($message->body)->toBe('Draft body')
        ->and($message->status)->toBe(MessageStatus::Draft);
});

test('dashboard can make a new message actionable in the main panel', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('panelAction', 'new-message')
        ->set('newMessageTitle', 'Ready to send')
        ->set('newMessageBody', 'Actionable body')
        ->set('newMessageTopicId', $topic->id)
        ->call('sendDashboardMessage')
        ->assertHasNoErrors()
        ->assertSet('panelAction', null)
        ->assertSet('selectedTopicSlug', $topic->slug)
        ->assertSet('selectedMessageSlug', 'ready-to-send');

    $message = $topic->messages()->where('title', 'Ready to send')->first();
    $senderPrincipal = $workspace->principalForUser($user);

    expect($message)->not->toBeNull()
        ->and($message->body)->toBe('Actionable body')
        ->and($message->sender_principal_id)->toBe($senderPrincipal->id)
        ->and($message->status)->toBe(MessageStatus::Published);
});

test('dashboard can assign agents when sending a new message', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $agents = Agent::factory()->count(2)->for($workspace)->create();
    $topic->agents()->attach($agents->pluck('id'));

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('panelAction', 'new-message')
        ->set('newMessageTitle', 'Agent assignment')
        ->set('newMessageBody', 'Please both review this.')
        ->set('newMessageTopicId', $topic->id)
        ->set('newMessageAgentIds', $agents->pluck('id')->all())
        ->call('sendDashboardMessage')
        ->assertHasNoErrors();

    $message = $topic->messages()->where('title', 'Agent assignment')->first();

    expect($message)->not->toBeNull()
        ->and($message->agentTasks)->toHaveCount(2)
        ->and($message->agentTasks->pluck('event_type')->unique()->values()->all())->toBe([AgentTask::EventMessageAssigned])
        ->and($message->agentTasks->pluck('available_at')->filter())->toHaveCount(2);
});

test('dashboard can send a new message to a user', function () {
    [$user, $workspace] = userWithWorkspace();
    [, $recipientPrincipal] = teamMemberPrincipal($user, $workspace);

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('panelAction', 'new-message')
        ->set('newMessageTitle', 'User note')
        ->set('newMessageBody', 'For a person')
        ->set('newMessageTarget', 'principal')
        ->set('newMessageTopicId', $topic->id)
        ->set('newMessageRecipientPrincipalId', $recipientPrincipal->id)
        ->call('sendDashboardMessage')
        ->assertHasNoErrors();

    $message = $topic->messages()->where('title', 'User note')->first();
    $senderPrincipal = $workspace->principalForUser($user);

    expect($message)->not->toBeNull()
        ->and($message->sender_principal_id)->toBe($senderPrincipal->id)
        ->and($message->recipient_principal_id)->toBe($recipientPrincipal->id)
        ->and($message->status)->toBe(MessageStatus::Published);
});

test('dashboard can send a new message to an agent principal', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $agent = Agent::factory()->for($workspace)->create(['name' => 'Researcher']);
    $agentPrincipal = $workspace->principalForAgent($agent);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('panelAction', 'new-message')
        ->set('newMessageTitle', 'Agent note')
        ->set('newMessageBody', 'For an agent')
        ->set('newMessageTarget', 'principal')
        ->set('newMessageTopicId', $topic->id)
        ->set('newMessageRecipientPrincipalId', $agentPrincipal->id)
        ->call('sendDashboardMessage')
        ->assertHasNoErrors();

    $message = $topic->messages()->where('title', 'Agent note')->first();

    expect($message)->not->toBeNull()
        ->and($message->sender_principal_id)->toBe($workspace->principalForUser($user)->id)
        ->and($message->recipient_principal_id)->toBe($agentPrincipal->id)
        ->and($message->recipient->agent->name)->toBe('Researcher')
        ->and($message->status)->toBe(MessageStatus::Published);
});

test('dashboard defaults a principal recipient when none reached the server', function () {
    [$user, $workspace] = userWithWorkspace();
    $topic = Topic::factory()->for($workspace)->create(['slug' => 'design']);
    $senderPrincipal = $workspace->principalForUser($user);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('selectedTopicSlug', $topic->slug)
        ->set('panelAction', 'new-message')
        ->set('newMessageTitle', 'Default dashboard recipient')
        ->set('newMessageBody', 'Direct body')
        ->set('newMessageTarget', 'principal')
        ->set('newMessageTopicId', $topic->id)
        ->set('newMessageRecipientPrincipalId', null)
        ->call('sendDashboardMessage')
        ->assertHasNoErrors();

    $message = $topic->messages()->where('title', 'Default dashboard recipient')->first();

    expect($message)->not->toBeNull()
        ->and($message->sender_principal_id)->toBe($senderPrincipal->id)
        ->and($message->recipient_principal_id)->toBe($senderPrincipal->id)
        ->and($message->status)->toBe(MessageStatus::Published);
});

test('dashboard shows mobile bottom navigation with topics active by default', function () {
    [$user, $workspace] = userWithWorkspace();

    Topic::factory()->for($workspace)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-mobile-nav="topics"', escape: false)
        ->assertSee('data-mobile-nav="messages"', escape: false)
        ->assertSee('data-mobile-nav="agents"', escape: false)
        ->assertSee('aria-pressed="true"', escape: false)
        ->assertSee('data-mobile-panel="topics"', escape: false)
        ->assertSee('min-h-[calc(100dvh-4rem)]', escape: false)
        ->assertSee('data-mobile-panel="agents"', escape: false)
        ->assertSee('xl:h-full', escape: false)
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

test('dashboard without a selected topic shows a top-level new message action', function () {
    [$user, $workspace] = userWithWorkspace();

    Topic::factory()->for($workspace)->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['panel' => 'messages']))
        ->assertOk()
        ->assertSee('Select a topic')
        ->assertSee('data-mobile-panel="topics"', escape: false)
        ->assertSee('New message')
        ->assertSee(e(route('messages.create')), escape: false)
        ->assertSee('data-mobile-nav="messages"', escape: false)
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

test('topic page left aligns message icons in icon view', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create();
    $message = Message::factory()->for($topic)->create([
        'updated_at' => now()->subMinutes(13),
        'status' => MessageStatus::Published,
        'sender_principal_id' => $workspace->principalForUser($user)->id,
    ]);
    Attachment::factory()->for($message)->create();

    $this->actingAs($user)
        ->get(route('topics.show', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertSee('Messages')
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
        ->assertSee('data-test="folder-list-sort-from"', escape: false)
        ->assertSee('data-test="folder-list-sort-sent"', escape: false)
        ->assertSee('data-test="folder-list-sort-attachments"', escape: false)
        ->assertSee('data-sort-sent=', escape: false)
        ->assertSee('data-sort-attachments=', escape: false)
        ->assertSee('wire:key="folder-icon-', escape: false)
        ->assertSee('wire:key="folder-list-', escape: false)
        ->assertSeeText('From:')
        ->assertSeeText($user->name)
        ->assertSeeText('Sent:')
        ->assertSeeText('13 minutes ago')
        ->assertSee('grid grid-cols-1 items-stretch gap-3 xl:flex-1 xl:auto-rows-fr xl:grid-cols-[minmax(0,1fr)_19rem]', escape: false)
        ->assertSee('xl:h-full', escape: false)
        ->assertDontSee('xl:sticky xl:top-6');
});

test('topic message list has a deterministic default order', function () {
    [$user, $workspace] = userWithWorkspace();

    $topic = Topic::factory()->for($workspace)->create();
    $timestamp = now()->subMinutes(10);

    Message::factory()->for($topic)->create([
        'title' => 'Older message',
        'status' => MessageStatus::Published,
        'updated_at' => now()->subHour(),
    ]);

    $firstTie = Message::factory()->for($topic)->create([
        'title' => 'First tied message',
        'status' => MessageStatus::Published,
        'updated_at' => $timestamp,
    ]);

    $secondTie = Message::factory()->for($topic)->create([
        'title' => 'Second tied message',
        'status' => MessageStatus::Published,
        'updated_at' => $timestamp,
    ]);

    Message::factory()->for($topic)->create([
        'title' => 'Newest message',
        'status' => MessageStatus::Published,
        'updated_at' => now(),
    ]);

    expect($secondTie->id)->toBeGreaterThan($firstTie->id);

    $this->actingAs($user)
        ->get(route('topics.show', ['topic' => $topic->slug]))
        ->assertOk()
        ->assertSeeInOrder([
            'Newest message',
            'Second tied message',
            'First tied message',
            'Older message',
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
