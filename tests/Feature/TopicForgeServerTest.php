<?php

use App\Enums\MessageStatus;
use App\Mcp\Resources\AgentResource;
use App\Mcp\Resources\MessageResource;
use App\Mcp\Resources\PlaybookResource;
use App\Mcp\Resources\TopicMessagesResource;
use App\Mcp\Resources\TopicResource;
use App\Mcp\Resources\WhoamiResource;
use App\Mcp\Resources\WorkspaceAgentsResource;
use App\Mcp\Resources\WorkspacesResource;
use App\Mcp\Resources\WorkspaceTopicsResource;
use App\Mcp\Servers\TopicForgeServer;
use App\Mcp\Tools\CreateMessageTool;
use App\Mcp\Tools\GetAgentTool;
use App\Mcp\Tools\GetMessageTool;
use App\Mcp\Tools\GetTopicTool;
use App\Mcp\Tools\ListAgentsTool;
use App\Mcp\Tools\ListMessagesTool;
use App\Mcp\Tools\ListTopicsTool;
use App\Mcp\Tools\ListWorkspacesTool;
use App\Mcp\Tools\SwitchWorkspaceTool;
use App\Mcp\TopicForgeContext;
use App\Models\Agent;
use App\Models\AgentVersion;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\Topic;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Container\Container;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;
use Symfony\Component\Process\Process;

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

test('switch workspace updates the current mcp workspace context', function () {
    $user = User::factory()->create();
    $initialWorkspace = Workspace::factory()->for($user->currentTeam)->create([
        'name' => 'Initial Workspace',
        'slug' => 'initial-workspace',
    ]);
    $targetWorkspace = Workspace::factory()->for($user->currentTeam)->create([
        'name' => 'Target Workspace',
        'slug' => 'target-workspace',
    ]);
    Topic::factory()->for($initialWorkspace)->create([
        'name' => 'Hidden Topic',
        'slug' => 'hidden-topic',
    ]);
    $targetTopic = Topic::factory()->for($targetWorkspace)->create([
        'name' => 'Target Topic',
        'slug' => 'target-topic',
    ]);
    $user->switchWorkspace($initialWorkspace);

    $switchResponse = TopicForgeServer::actingAs($user)->tool(SwitchWorkspaceTool::class, [
        'workspace_slug' => 'target-workspace',
    ]);

    $switchResponse
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('workspace.slug', 'target-workspace')
            ->where('workspace.is_current', true)
            ->etc()
        );

    expect($user->refresh()->currentWorkspace?->slug)->toBe('target-workspace');

    $topicsResponse = TopicForgeServer::actingAs($user)->tool(ListTopicsTool::class, []);

    $topicsResponse
        ->assertOk()
        ->assertStructuredContent([
            'workspace' => $targetWorkspace->only(['id', 'name', 'slug']),
            'topics' => [
                [
                    'id' => $targetTopic->id,
                    'name' => 'Target Topic',
                    'slug' => 'target-topic',
                    'messages_count' => 0,
                    'resource_uri' => 'topic-forge://workspaces/target-workspace/topics/target-topic',
                ],
            ],
        ]);
});

test('topic forge tools expose switch workspace instead of workspace slug parameters', function () {
    $response = topicForgeServerMethodResponse('tools/list');
    $tools = collect($response['result']['tools'])->keyBy('name');

    expect($tools)->toHaveKey('switch-workspace');
    expect($tools['switch-workspace']['inputSchema']['properties'])->toHaveKey('workspace_slug');

    foreach ([
        'list-topics',
        'list-agents',
        'get-topic',
        'get-agent',
        'list-messages',
        'get-message',
        'create-message',
    ] as $toolName) {
        expect($tools[$toolName]['inputSchema']['properties'] ?? [])->not->toHaveKey('workspace_slug');
    }
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
            ->where('agents.0.resource_uri', 'topic-forge://workspaces/strategy/agents/research-agent')
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
            ->where('agent.resource_uri', 'topic-forge://workspaces/strategy/agents/research-agent')
            ->where('topics.0.slug', 'alpha-topic')
            ->where('topics.0.resource_uri', 'topic-forge://workspaces/strategy/topics/alpha-topic')
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
            ->where('topic.resource_uri', 'topic-forge://workspaces/strategy/topics/alpha-topic')
            ->where('messages.0.title', 'Alpha Draft')
            ->where('messages.0.has_body', false)
            ->where('messages.0.resource_uri', 'topic-forge://workspaces/strategy/topics/alpha-topic/messages/alpha-draft')
            ->where('messages.1.title', 'Zulu Note')
            ->where('messages.1.status', 'published')
            ->where('messages.1.has_body', true)
            ->where('messages.1.resource_uri', 'topic-forge://workspaces/strategy/topics/alpha-topic/messages/zulu-note')
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
            ->where('topic.resource_uri', 'topic-forge://workspaces/strategy/topics/alpha-topic')
            ->where('message.slug', 'alpha-draft')
            ->where('message.body', 'Draft body')
            ->where('message.resource_uri', 'topic-forge://workspaces/strategy/topics/alpha-topic/messages/alpha-draft')
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
            ->where('topic.resource_uri', 'topic-forge://workspaces/strategy/topics/alpha-topic')
            ->where('message.title', 'New MCP Message')
            ->where('message.status', 'draft')
            ->where('message.resource_uri', 'topic-forge://workspaces/strategy/topics/alpha-topic/messages/new-mcp-message')
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
        ->assertSee('"resource_uri":"topic-forge://workspaces/strategy/topics/alpha-topic"')
        ->assertSee('"title":"Alpha Draft"')
        ->assertSee('"resource_uri":"topic-forge://workspaces/strategy/topics/alpha-topic/messages/alpha-draft"')
        ->assertSee('"body":"Draft body"');
});

test('workspace topics resource returns topics for a workspace by uri template', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'name' => 'Alpha Topic',
        'slug' => 'alpha-topic',
    ]);
    Message::factory()->for($topic)->count(2)->create();

    $resource = new class(app(TopicForgeContext::class)) extends WorkspaceTopicsResource
    {
        public function uri(): string
        {
            return 'topic-forge://workspaces/strategy/topics';
        }
    };

    $response = TopicForgeServer::actingAs($user)->resource($resource);

    $response
        ->assertOk()
        ->assertSee('"slug":"strategy"')
        ->assertSee('"slug":"alpha-topic"')
        ->assertSee('"messages_count":2')
        ->assertSee('"resource_uri":"topic-forge://workspaces/strategy/topics/alpha-topic"');
});

test('workspace topics resource is readable through a concrete template uri', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'name' => 'Alpha Topic',
        'slug' => 'alpha-topic',
    ]);
    Message::factory()->for($topic)->count(2)->create();

    app('auth')->guard()->setUser($user);
    app('auth')->shouldUse(null);

    $response = topicForgeServerMethodResponse('resources/read', [
        'uri' => 'topic-forge://workspaces/strategy/topics',
    ]);

    expect($response['result']['contents'][0]['uri'])->toBe('topic-forge://workspaces/strategy/topics');
    expect($response['result']['contents'][0]['text'])->toContain('"slug":"strategy"');
    expect($response['result']['contents'][0]['text'])->toContain('"slug":"alpha-topic"');
    expect($response['result']['contents'][0]['text'])->toContain('"messages_count":2');
    expect($response['result']['contents'][0]['text'])->toContain('"resource_uri":"topic-forge://workspaces/strategy/topics/alpha-topic"');
});

test('workspace agents resource returns agents for a workspace by uri template', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'slug' => 'alpha-topic',
    ]);
    $agent = Agent::factory()->for($workspace)->create([
        'name' => 'Research Agent',
        'slug' => 'research-agent',
    ]);
    AgentVersion::factory()->for($agent)->create([
        'version' => 3,
        'model' => 'o4-mini',
    ]);
    $topic->agents()->attach($agent);

    $resource = new class(app(TopicForgeContext::class)) extends WorkspaceAgentsResource
    {
        public function uri(): string
        {
            return 'topic-forge://workspaces/strategy/agents';
        }
    };

    $response = TopicForgeServer::actingAs($user)->resource($resource);

    $response
        ->assertOk()
        ->assertSee('"slug":"strategy"')
        ->assertSee('"slug":"research-agent"')
        ->assertSee('"topics_count":1')
        ->assertSee('"resource_uri":"topic-forge://workspaces/strategy/agents/research-agent"');
});

test('topic messages resource returns messages for a topic by uri template', function () {
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

    $resource = new class(app(TopicForgeContext::class)) extends TopicMessagesResource
    {
        public function uri(): string
        {
            return 'topic-forge://workspaces/strategy/topics/alpha-topic/messages';
        }
    };

    $response = TopicForgeServer::actingAs($user)->resource($resource);

    $response
        ->assertOk()
        ->assertSee('"slug":"alpha-topic"')
        ->assertSee('"title":"Alpha Draft"')
        ->assertSee('"has_body":false')
        ->assertSee('"resource_uri":"topic-forge://workspaces/strategy/topics/alpha-topic/messages/alpha-draft"')
        ->assertSee('"title":"Zulu Note"');
});

test('message resource returns message context by uri template', function () {
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

    $resource = new class(app(TopicForgeContext::class)) extends MessageResource
    {
        public function uri(): string
        {
            return 'topic-forge://workspaces/strategy/topics/alpha-topic/messages/alpha-draft';
        }
    };

    $response = TopicForgeServer::actingAs($user)->resource($resource);

    $response
        ->assertOk()
        ->assertSee('"slug":"alpha-draft"')
        ->assertSee('"resource_uri":"topic-forge://workspaces/strategy/topics/alpha-topic/messages/alpha-draft"')
        ->assertSee('"filename":"report.pdf"');
});

test('agent resource returns agent context by uri template', function () {
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
        'version' => 2,
        'model' => 'o4-mini',
        'prompt' => 'Second prompt',
    ]);
    $topic->agents()->attach($agent);

    $resource = new class(app(TopicForgeContext::class)) extends AgentResource
    {
        public function uri(): string
        {
            return 'topic-forge://workspaces/strategy/agents/research-agent';
        }
    };

    $response = TopicForgeServer::actingAs($user)->resource($resource);

    $response
        ->assertOk()
        ->assertSee('"slug":"research-agent"')
        ->assertSee('"resource_uri":"topic-forge://workspaces/strategy/agents/research-agent"')
        ->assertSee('"resource_uri":"topic-forge://workspaces/strategy/topics/alpha-topic"');
});

test('topic forge server lists top-level resources', function () {
    $response = topicForgeServerMethodResponse('resources/list');

    expect($response['result']['resources'])->toHaveCount(3);

    expect(collect($response['result']['resources'])->contains(
        fn (array $resource): bool => $resource['name'] === 'whoami-resource'
            && $resource['uri'] === 'topic-forge://whoami'
            && $resource['mimeType'] === 'application/json'
    ))->toBeTrue();

    expect(collect($response['result']['resources'])->contains(
        fn (array $resource): bool => $resource['name'] === 'playbook-resource'
            && $resource['uri'] === 'topic-forge://playbook'
            && $resource['mimeType'] === 'application/json'
    ))->toBeTrue();

    expect(collect($response['result']['resources'])->contains(
        fn (array $resource): bool => $resource['name'] === 'workspaces-resource'
            && $resource['uri'] === 'topic-forge://workspaces'
            && $resource['mimeType'] === 'application/json'
    ))->toBeTrue();
});

test('playbook resource returns top-level navigation guidance', function () {
    $response = TopicForgeServer::resource(PlaybookResource::class);

    $response
        ->assertOk()
        ->assertSee('"resource_uri":"topic-forge://playbook"')
        ->assertSee('topic-forge://workspaces/{workspace}/topics/{topic}/messages/{message}');
});

test('playbook resource is readable by its static uri', function () {
    $response = topicForgeServerMethodResponse('resources/read', [
        'uri' => 'topic-forge://playbook',
    ]);

    expect($response['result']['contents'][0]['uri'])->toBe('topic-forge://playbook');
    expect($response['result']['contents'][0]['text'])->toContain('"resource_uri":"topic-forge://playbook"');
    expect($response['result']['contents'][0]['text'])->toContain('Topic Forge Playbook');
});

test('whoami resource returns authenticated mcp identity', function () {
    $user = User::factory()->create([
        'name' => 'Local Agent',
        'email' => 'whoami@example.com',
    ]);
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'name' => 'Whoami Workspace',
        'slug' => 'whoami-workspace',
    ]);
    $user->switchWorkspace($workspace);

    $response = TopicForgeServer::actingAs($user)->resource(WhoamiResource::class);

    $response
        ->assertOk()
        ->assertSee('"resource_uri":"topic-forge://whoami"')
        ->assertSee('"authenticated":true')
        ->assertSee('"email":"whoami@example.com"')
        ->assertSee('"slug":"whoami-workspace"');
});

test('whoami resource is readable by its static uri', function () {
    $user = User::factory()->create([
        'name' => 'Local Agent',
        'email' => 'whoami-static@example.com',
    ]);
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'whoami-static-workspace',
    ]);
    $user->switchWorkspace($workspace);

    app('auth')->guard()->setUser($user);
    app('auth')->shouldUse(null);

    $response = topicForgeServerMethodResponse('resources/read', [
        'uri' => 'topic-forge://whoami',
    ]);

    expect($response['result']['contents'][0]['uri'])->toBe('topic-forge://whoami');
    expect($response['result']['contents'][0]['text'])->toContain('"email":"whoami-static@example.com"');
    expect($response['result']['contents'][0]['text'])->toContain('"slug":"whoami-static-workspace"');
});

test('workspaces resource returns current team workspaces', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'name' => 'Current Workspace',
        'slug' => 'current-workspace',
    ]);
    Workspace::factory()->for($user->currentTeam)->create([
        'name' => 'Research Workspace',
        'slug' => 'research-workspace',
    ]);
    $user->switchWorkspace($workspace);

    $response = TopicForgeServer::actingAs($user)->resource(WorkspacesResource::class);

    $response
        ->assertOk()
        ->assertSee('"resource_uri":"topic-forge://workspaces"')
        ->assertSee('"slug":"current-workspace"')
        ->assertSee('"is_current":true')
        ->assertSee('"topics_resource_uri":"topic-forge://workspaces/current-workspace/topics"')
        ->assertSee('"agents_resource_uri":"topic-forge://workspaces/research-workspace/agents"');
});

test('workspaces resource is readable by its static uri', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'name' => 'Current Workspace',
        'slug' => 'current-workspace',
    ]);
    $user->switchWorkspace($workspace);

    app('auth')->guard()->setUser($user);
    app('auth')->shouldUse(null);

    $response = topicForgeServerMethodResponse('resources/read', [
        'uri' => 'topic-forge://workspaces',
    ]);

    expect($response['result']['contents'][0]['uri'])->toBe('topic-forge://workspaces');
    expect($response['result']['contents'][0]['text'])->toContain('"resource_uri":"topic-forge://workspaces"');
    expect($response['result']['contents'][0]['text'])->toContain('"slug":"current-workspace"');
    expect($response['result']['contents'][0]['text'])->not->toContain('Topic Forge Playbook');
});

test('local mcp resource auth errors stay on the json rpc channel', function () {
    $process = new Process(
        ['php', 'artisan', 'mcp:start', 'topic-forge', '--no-interaction'],
        base_path(),
        [
            'APP_ENV' => 'testing',
            'MCP_LOCAL_AUTH_USER' => '',
        ],
    );
    $process->setInput(implode("\n", [
        '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"debug","version":"0.0.1"}}}',
        '{"jsonrpc":"2.0","id":2,"method":"resources/read","params":{"uri":"topic-forge://workspaces"}}',
    ])."\n");

    $process->mustRun();

    $output = trim($process->getOutput());

    expect($output)->not->toContain('Illuminate\\Auth\\AuthenticationException');

    $messages = collect(explode("\n", $output))
        ->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR));

    expect($messages)->toHaveCount(2);
    expect($messages[1]['error']['message'])->toBe('You must be authenticated to use the Topic Forge MCP server.');
});

test('local mcp resources authenticate from the configured env user', function () {
    $user = User::factory()->create([
        'email' => 'local-resource@example.com',
    ]);
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'name' => 'Local Resource Workspace',
        'slug' => 'local-resource-workspace',
    ]);
    $user->switchWorkspace($workspace);

    config()->set('mcp.local_auth_user', 'local-resource@example.com');
    auth()->guard('web')->logout();

    $response = topicForgeServerMethodResponse('resources/read', [
        'uri' => 'topic-forge://workspaces',
    ]);

    expect($response['result']['contents'][0]['text'])->toContain('"slug":"local-resource-workspace"');
    expect($response['result']['contents'][0]['text'])->not->toContain('You must be authenticated');
});

test('invalid local mcp auth config does not write laravel exceptions to stdout', function () {
    $process = new Process(
        ['php', 'artisan', 'mcp:start', 'topic-forge', '--no-interaction'],
        base_path(),
        [
            'APP_ENV' => 'testing',
            'MCP_LOCAL_AUTH_USER' => 'missing-local-user@example.invalid',
        ],
    );
    $process->setInput(implode("\n", [
        '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"debug","version":"0.0.1"}}}',
        '{"jsonrpc":"2.0","id":2,"method":"resources/read","params":{"uri":"topic-forge://workspaces"}}',
    ])."\n");

    $process->mustRun();

    $output = trim($process->getOutput());

    expect($output)->not->toContain('Illuminate\\Auth\\AuthenticationException');

    $messages = collect(explode("\n", $output))
        ->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR));

    expect($messages)->toHaveCount(2);
    expect($messages[0]['result']['serverInfo']['name'])->toBe('Topic Forge Server');
    expect($messages[1]['error']['message'])->toBe('The configured MCP local auth user could not be resolved.');
});

test('topic forge server lists topic, message, and agent resource templates', function () {
    $response = topicForgeServerMethodResponse('resources/templates/list');

    expect($response['result']['resourceTemplates'])->toHaveCount(6);

    expect(collect($response['result']['resourceTemplates'])->contains(
        fn (array $resource): bool => $resource['uriTemplate'] === 'topic-forge://workspaces/{workspace}/topics'
    ))->toBeTrue();

    expect(collect($response['result']['resourceTemplates'])->contains(
        fn (array $resource): bool => $resource['uriTemplate'] === 'topic-forge://workspaces/{workspace}/agents'
    ))->toBeTrue();

    expect(collect($response['result']['resourceTemplates'])->contains(
        fn (array $resource): bool => $resource['uriTemplate'] === 'topic-forge://workspaces/{workspace}/topics/{topic}/messages'
    ))->toBeTrue();

    expect(collect($response['result']['resourceTemplates'])->contains(
        fn (array $resource): bool => $resource['name'] === 'topic-resource'
            && $resource['uriTemplate'] === 'topic-forge://workspaces/{workspace}/topics/{topic}'
    ))->toBeTrue();

    expect(collect($response['result']['resourceTemplates'])->contains(
        fn (array $resource): bool => $resource['name'] === 'message-resource'
            && $resource['uriTemplate'] === 'topic-forge://workspaces/{workspace}/topics/{topic}/messages/{message}'
    ))->toBeTrue();

    expect(collect($response['result']['resourceTemplates'])->contains(
        fn (array $resource): bool => $resource['name'] === 'agent-resource'
            && $resource['uriTemplate'] === 'topic-forge://workspaces/{workspace}/agents/{agent}'
    ))->toBeTrue();
});

test('workspace access is denied outside the current team scope', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

    $foreignWorkspace = Workspace::factory()->create([
        'slug' => 'foreign-workspace',
    ]);

    $response = TopicForgeServer::actingAs($user)->tool(SwitchWorkspaceTool::class, [
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

function topicForgeServerMethodResponse(string $method, array $params = []): array
{
    $server = Container::getInstance()->make(
        TopicForgeServer::class,
        ['transport' => new FakeTransporter]
    );
    $server->start();

    $request = new JsonRpcRequest(uniqid(), $method, $params);

    /** @var JsonRpcResponse $response */
    $response = (fn (): iterable|JsonRpcResponse => $this->runMethodHandle($request, $this->createContext()))->call($server);

    return $response->toArray();
}
