<?php

use App\Enums\MessageStatus;
use App\Mcp\Resources\TopicResource;
use App\Mcp\Servers\TopicForgeServer;
use App\Mcp\Tools\CreateMessageTool;
use App\Mcp\Tools\GetAgentTool;
use App\Mcp\Tools\GetMessageTool;
use App\Mcp\Tools\GetTopicTool;
use App\Mcp\Tools\ListAgentsTool;
use App\Mcp\Tools\ListMessagesTool;
use App\Mcp\Tools\ListTopicsTool;
use App\Mcp\Tools\ListWorkspacesTool;
use App\Mcp\TopicForgeContext;
use App\Models\Agent;
use App\Models\AgentVersion;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Topic;
use App\Models\User;
use App\Models\Workspace;

test('list workspaces returns current team workspaces', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'name' => 'Current Workspace',
        'slug' => 'current-workspace',
    ]);
    $otherWorkspace = Workspace::factory()->for($user->currentTeam)->create([
        'name' => 'Research Workspace',
        'slug' => 'research-workspace',
    ]);
    $user->switchWorkspace($workspace);

    $response = TopicForgeServer::actingAs($user)->tool(ListWorkspacesTool::class, []);

    $response
        ->assertOk()
        ->assertStructuredContent([
            'team' => $user->currentTeam->only(['id', 'name', 'slug']),
            'workspaces' => [
                [
                    'id' => $workspace->id,
                    'name' => 'Current Workspace',
                    'slug' => 'current-workspace',
                    'is_current' => true,
                ],
                [
                    'id' => $otherWorkspace->id,
                    'name' => 'Research Workspace',
                    'slug' => 'research-workspace',
                    'is_current' => false,
                ],
            ],
        ]);
});

test('list topics defaults to the current workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'name' => 'Strategy',
        'slug' => 'strategy',
    ]);
    $otherWorkspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'name' => 'Alpha Topic',
        'slug' => 'alpha-topic',
    ]);
    Message::factory()->for($topic)->count(2)->create();
    Topic::factory()->for($otherWorkspace)->create([
        'name' => 'Hidden Topic',
        'slug' => 'hidden-topic',
    ]);

    $response = TopicForgeServer::actingAs($user)->tool(ListTopicsTool::class, []);

    $response
        ->assertOk()
        ->assertStructuredContent([
            'workspace' => $workspace->only(['id', 'name', 'slug']),
            'topics' => [
                [
                    'id' => $topic->id,
                    'name' => 'Alpha Topic',
                    'slug' => 'alpha-topic',
                    'messages_count' => 2,
                    'resource_uri' => 'topic-forge://workspaces/strategy/topics/alpha-topic',
                ],
            ],
        ]);
});

test('get topic returns attached agents for an accessible workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'name' => 'Strategy',
        'slug' => 'strategy',
    ]);
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'name' => 'Alpha Topic',
        'slug' => 'alpha-topic',
    ]);
    Message::factory()->for($topic)->count(2)->create();

    $agent = Agent::factory()->for($workspace)->create([
        'name' => 'Research Agent',
        'slug' => 'research-agent',
    ]);
    AgentVersion::factory()->for($agent)->create([
        'version' => 1,
        'model' => 'o4-mini',
    ]);
    $topic->agents()->attach($agent);
    $agent->load('latestVersion');

    $response = TopicForgeServer::actingAs($user)->tool(GetTopicTool::class, [
        'topic_slug' => 'alpha-topic',
    ]);

    $response
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('workspace.slug', 'strategy')
            ->where('topic.slug', 'alpha-topic')
            ->where('topic.messages_count', 2)
            ->where('topic.resource_uri', 'topic-forge://workspaces/strategy/topics/alpha-topic')
            ->where('agents.0.slug', 'research-agent')
            ->where('agents.0.latest_version', 1)
            ->etc()
        );
});

test('list agents returns workspace agents with topic counts and latest versions', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $otherWorkspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

    $visibleTopic = Topic::factory()->for($workspace)->create([
        'name' => 'Visible Topic',
        'slug' => 'visible-topic',
    ]);
    $agent = Agent::factory()->for($workspace)->create([
        'name' => 'Research Agent',
        'slug' => 'research-agent',
    ]);
    AgentVersion::factory()->for($agent)->create([
        'version' => 3,
        'model' => 'o4-mini',
    ]);
    $visibleTopic->agents()->attach($agent);

    Agent::factory()->for($otherWorkspace)->create([
        'name' => 'Hidden Agent',
        'slug' => 'hidden-agent',
    ]);

    $response = TopicForgeServer::actingAs($user)->tool(ListAgentsTool::class, []);

    $response
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('workspace.slug', 'strategy')
            ->where('agents.0.slug', 'research-agent')
            ->where('agents.0.topics_count', 1)
            ->where('agents.0.latest_version', 3)
            ->where('agents.0.latest_model', 'o4-mini')
            ->etc()
        );
});

test('get agent returns topics and version history for an accessible workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'name' => 'Alpha Topic',
        'slug' => 'alpha-topic',
    ]);
    $agent = Agent::factory()->for($workspace)->create([
        'name' => 'Research Agent',
        'slug' => 'research-agent',
    ]);
    AgentVersion::factory()->for($agent)->create([
        'version' => 1,
        'model' => 'claude-sonnet-4-6',
        'prompt' => 'First prompt',
    ]);
    AgentVersion::factory()->for($agent)->create([
        'version' => 2,
        'model' => 'o4-mini',
        'prompt' => 'Second prompt',
    ]);
    $topic->agents()->attach($agent);

    $response = TopicForgeServer::actingAs($user)->tool(GetAgentTool::class, [
        'agent_slug' => 'research-agent',
    ]);

    $response
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('workspace.slug', 'strategy')
            ->where('agent.slug', 'research-agent')
            ->where('agent.latest_version', 2)
            ->where('topics.0.slug', 'alpha-topic')
            ->where('versions.0.version', 2)
            ->where('versions.0.model', 'o4-mini')
            ->where('versions.0.prompt', 'Second prompt')
            ->where('versions.1.version', 1)
            ->etc()
        );
});

test('list messages returns topic messages ordered by title', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'slug' => 'alpha-topic',
    ]);
    Message::factory()->for($topic)->create([
        'title' => 'Zulu Note',
        'slug' => 'zulu-note',
        'status' => MessageStatus::Published,
        'body' => 'Ready to ship.',
    ]);
    Message::factory()->for($topic)->create([
        'title' => 'Alpha Draft',
        'slug' => 'alpha-draft',
        'status' => MessageStatus::Draft,
        'body' => null,
    ]);

    $response = TopicForgeServer::actingAs($user)->tool(ListMessagesTool::class, [
        'topic_slug' => 'alpha-topic',
    ]);

    $response
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('workspace.slug', 'strategy')
            ->where('topic.slug', 'alpha-topic')
            ->where('messages.0.title', 'Alpha Draft')
            ->where('messages.0.has_body', false)
            ->where('messages.1.title', 'Zulu Note')
            ->where('messages.1.status', 'published')
            ->where('messages.1.has_body', true)
            ->etc()
        );
});

test('get message returns the message body and attachment metadata', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'name' => 'Alpha Topic',
        'slug' => 'alpha-topic',
    ]);
    $message = Message::factory()->for($topic)->create([
        'title' => 'Alpha Draft',
        'slug' => 'alpha-draft',
        'status' => MessageStatus::Draft,
        'body' => 'Draft body',
    ]);
    Attachment::factory()->for($message)->create([
        'filename' => 'report.pdf',
        'mime_type' => 'application/pdf',
        'size' => 4096,
    ]);

    $response = TopicForgeServer::actingAs($user)->tool(GetMessageTool::class, [
        'topic_slug' => 'alpha-topic',
        'message_slug' => 'alpha-draft',
    ]);

    $response
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('workspace.slug', 'strategy')
            ->where('topic.slug', 'alpha-topic')
            ->where('message.slug', 'alpha-draft')
            ->where('message.body', 'Draft body')
            ->where('attachments.0.filename', 'report.pdf')
            ->where('attachments.0.mime_type', 'application/pdf')
            ->where('attachments.0.size', 4096)
            ->etc()
        );
});

test('create message creates a draft by default', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'slug' => 'alpha-topic',
    ]);

    $response = TopicForgeServer::actingAs($user)->tool(CreateMessageTool::class, [
        'topic_slug' => 'alpha-topic',
        'title' => 'New MCP Message',
        'body' => 'Created through the MCP server.',
    ]);

    $message = $topic->messages()->where('title', 'New MCP Message')->first();

    expect($message)->not->toBeNull();
    expect($message?->status)->toBe(MessageStatus::Draft);
    expect($message?->slug)->toBe('new-mcp-message');

    $response
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('workspace.slug', 'strategy')
            ->where('topic.slug', 'alpha-topic')
            ->where('message.title', 'New MCP Message')
            ->where('message.status', 'draft')
            ->etc()
        );
});

test('topic resource returns topic context by uri template', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'name' => 'Alpha Topic',
        'slug' => 'alpha-topic',
    ]);
    Message::factory()->for($topic)->create([
        'title' => 'Alpha Draft',
        'slug' => 'alpha-draft',
        'status' => MessageStatus::Draft,
        'body' => 'Draft body',
    ]);

    $resource = new class(app(TopicForgeContext::class)) extends TopicResource
    {
        public function uri(): string
        {
            return 'topic-forge://workspaces/strategy/topics/alpha-topic';
        }
    };

    $response = TopicForgeServer::actingAs($user)->resource($resource);

    $response
        ->assertOk()
        ->assertSee('"slug":"alpha-topic"')
        ->assertSee('"title":"Alpha Draft"')
        ->assertSee('"body":"Draft body"');
});

test('workspace access is denied outside the current team scope', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

    $foreignWorkspace = Workspace::factory()->create([
        'slug' => 'foreign-workspace',
    ]);

    $response = TopicForgeServer::actingAs($user)->tool(ListTopicsTool::class, [
        'workspace_slug' => $foreignWorkspace->slug,
    ]);

    $response->assertHasErrors();
});

test('local mcp auth can resolve the user from config', function () {
    $user = User::factory()->create([
        'email' => 'local-agent@example.com',
    ]);
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'name' => 'Local Workspace',
        'slug' => 'local-workspace',
    ]);
    $user->switchWorkspace($workspace);

    config()->set('mcp.local_auth_user', 'local-agent@example.com');
    auth()->guard('web')->logout();

    $response = TopicForgeServer::tool(ListWorkspacesTool::class, []);

    $response
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('team.slug', $user->currentTeam->slug)
            ->where('workspaces.0.slug', 'local-workspace')
            ->etc()
        );
});

test('oauth metadata endpoint advertises passport endpoints for the mcp server', function () {
    $response = $this->getJson('/.well-known/oauth-authorization-server/mcp/topic-forge');

    $response
        ->assertOk()
        ->assertJsonPath('issuer', config('mcp.authorization_server') ?? url('/'))
        ->assertJsonPath('authorization_endpoint', route('passport.authorizations.authorize'))
        ->assertJsonPath('token_endpoint', route('passport.token'))
        ->assertJsonPath('registration_endpoint', url('oauth/register'))
        ->assertJsonPath('scopes_supported.0', 'mcp:use');
});

test('topic forge mcp route is protected by the passport api guard', function () {
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($route) => $route->uri() === 'mcp/topic-forge' && in_array('POST', $route->methods(), true));

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('auth:api');
});
