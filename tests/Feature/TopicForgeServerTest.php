<?php

use App\Enums\PostStatus;
use App\Enums\Provider;
use App\Enums\ReasoningEffort;
use App\Jobs\ProcessAgentTask;
use App\Mcp\Resources\AgentResource;
use App\Mcp\Resources\AgentTaskResource;
use App\Mcp\Resources\AgentTasksResource;
use App\Mcp\Resources\PlaybookResource;
use App\Mcp\Resources\PostResource;
use App\Mcp\Resources\TopicPostsResource;
use App\Mcp\Resources\TopicResource;
use App\Mcp\Resources\WhoamiResource;
use App\Mcp\Resources\WorkspaceAgentsResource;
use App\Mcp\Resources\WorkspacesResource;
use App\Mcp\Resources\WorkspaceTopicsResource;
use App\Mcp\Servers\TopicForgeServer;
use App\Mcp\Tools\CreateAgentTool;
use App\Mcp\Tools\CreatePostTool;
use App\Mcp\Tools\CreateTopicTool;
use App\Mcp\Tools\DeleteFileTool;
use App\Mcp\Tools\DeletePostTool;
use App\Mcp\Tools\GetAgentTaskTool;
use App\Mcp\Tools\GetAgentTool;
use App\Mcp\Tools\GetFileTool;
use App\Mcp\Tools\GetPostTool;
use App\Mcp\Tools\GetTopicTool;
use App\Mcp\Tools\ListAgentsTool;
use App\Mcp\Tools\ListAgentTasksTool;
use App\Mcp\Tools\ListFilesTool;
use App\Mcp\Tools\ListPostsTool;
use App\Mcp\Tools\ListReposTool;
use App\Mcp\Tools\ListTopicsTool;
use App\Mcp\Tools\ListWorkspacesTool;
use App\Mcp\Tools\RunGitCommandTool;
use App\Mcp\Tools\SwitchWorkspaceTool;
use App\Mcp\Tools\UpdateAgentTool;
use App\Mcp\Tools\UpdatePostTool;
use App\Mcp\Tools\WhoAmITool;
use App\Mcp\Tools\WriteFileTool;
use App\Mcp\TopicForgeContext;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\AgentVersion;
use App\Models\Attachment;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceRepository;
use App\Services\GitRepositoryService;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;
use Symfony\Component\Process\Process;

afterEach(function () {
    foreach (['app/workspaces', 'app/workspace-repos'] as $dir) {
        $path = storage_path($dir);
        if (is_dir($path)) {
            exec("rm -rf {$path}");
        }
    }
});

test('mcp uri literals are centralized', function () {
    $matches = collect(File::allFiles(app_path('Mcp')))
        ->reject(fn (SplFileInfo $file): bool => $file->getFilename() === 'TopicForgeUris.php')
        ->flatMap(function (SplFileInfo $file): array {
            preg_match_all('/topic-forge:\/\//', $file->getContents(), $fileMatches);

            return collect($fileMatches[0])
                ->map(fn (): string => $file->getRelativePathname())
                ->all();
        })
        ->values()
        ->all();

    expect($matches)->toBe([]);
});

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

test('who am i tool returns authenticated mcp identity', function () {
    $user = User::factory()->create([
        'name' => 'Local Agent',
        'email' => 'who-am-i@example.com',
    ]);
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'name' => 'Who Am I Workspace',
        'slug' => 'who-am-i-workspace',
    ]);
    $user->switchWorkspace($workspace);

    $response = TopicForgeServer::actingAs($user)->tool(WhoAmITool::class, []);

    $response
        ->assertOk()
        ->assertStructuredContent([
            'resource_uri' => 'topic-forge://whoami',
            'authenticated' => true,
            'user' => [
                'id' => $user->id,
                'name' => 'Local Agent',
                'email' => 'who-am-i@example.com',
            ],
            'team' => $user->currentTeam->only(['id', 'name', 'slug']),
            'workspace' => $workspace->only(['id', 'name', 'slug']),
        ]);
});

test('who am i tool reports an unauthenticated mcp session', function () {
    auth()->guard('web')->logout();

    $response = TopicForgeServer::tool(WhoAmITool::class, []);

    $response
        ->assertOk()
        ->assertStructuredContent([
            'resource_uri' => 'topic-forge://whoami',
            'authenticated' => false,
            'user' => null,
            'team' => null,
            'workspace' => null,
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
    Post::factory()->for($topic)->count(2)->create();
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
                    'posts_count' => 2,
                    'resource_uri' => 'topic-forge://workspaces/strategy/topics/alpha-topic',
                ],
            ],
        ]);
});

test('switch workspace inbox the current mcp workspace context', function () {
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
                    'posts_count' => 0,
                    'resource_uri' => 'topic-forge://workspaces/target-workspace/topics/target-topic',
                ],
            ],
        ]);
});

test('topic forge tools expose switch workspace instead of workspace slug parameters', function () {
    $response = topicForgeServerMethodResponse('tools/list');
    $tools = collect($response['result']['tools'])->keyBy('name');

    expect($response['result'])->not->toHaveKey('nextCursor');
    expect($tools)->toHaveKey('who-am-i');
    expect($tools)->toHaveKey('switch-workspace');
    expect($tools)->toHaveKey('delete-post');
    expect($tools['switch-workspace']['inputSchema']['properties'])->toHaveKey('workspace_slug');

    foreach ([
        'list-topics',
        'list-agents',
        'list-agent-tasks',
        'get-agent-task',
        'get-topic',
        'get-agent',
        'list-posts',
        'get-post',
        'create-topic',
        'create-agent',
        'update-agent',
        'create-post',
        'update-post',
        'delete-post',
        'list-files',
        'get-file',
        'write-file',
        'delete-file',
        'list-repos',
        'run-git-command',
    ] as $toolName) {
        expect($tools[$toolName]['inputSchema']['properties'] ?? [])->not->toHaveKey('workspace_slug');
    }
});

test('workspace file tools let agents manage the current workspace filesystem', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $otherWorkspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

    $writeResponse = TopicForgeServer::actingAs($user)->tool(WriteFileTool::class, [
        'path' => 'docs/spec.md',
        'content' => "# Specification\n",
    ]);

    expect($workspace->filesystem()->exists('docs/spec.md'))->toBeTrue();
    expect($workspace->filesystem()->read('docs/spec.md'))->toBe("# Specification\n");
    expect($workspace->filesystem()->isDirectory('docs'))->toBeTrue();
    expect($otherWorkspace->filesystem()->exists('docs/spec.md'))->toBeFalse();

    $writeResponse
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('workspace.slug', 'strategy')
            ->where('file.path', 'docs/spec.md')
            ->where('file.content', "# Specification\n")
            ->where('file.dashboard_url', route('dashboard', [
                'action' => 'files',
                'file' => 'docs/spec.md',
                'panel' => 'posts',
            ]))
            ->etc()
        );

    TopicForgeServer::actingAs($user)->tool(ListFilesTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('workspace.slug', 'strategy')
            ->where('files.0.path', 'docs')
            ->where('files.1.path', 'docs/spec.md')
            ->where('files.1.dashboard_url', route('dashboard', [
                'action' => 'files',
                'file' => 'docs/spec.md',
                'panel' => 'posts',
            ]))
            ->etc()
        );

    TopicForgeServer::actingAs($user)->tool(GetFileTool::class, [
        'path' => 'docs/spec.md',
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('file.path', 'docs/spec.md')
            ->where('file.content', "# Specification\n")
            ->where('file.dashboard_url', route('dashboard', [
                'action' => 'files',
                'file' => 'docs/spec.md',
                'panel' => 'posts',
            ]))
            ->etc()
        );

    TopicForgeServer::actingAs($user)->tool(DeleteFileTool::class, [
        'path' => 'docs',
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('file.path', 'docs')
            ->where('deleted', true)
            ->etc()
        );

    expect($workspace->filesystem()->exists('docs'))->toBeFalse();
});

test('create topic creates a topic in the current workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $otherWorkspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

    $response = TopicForgeServer::actingAs($user)->tool(CreateTopicTool::class, [
        'name' => 'General',
    ]);

    $topic = $workspace->topics()->where('name', 'General')->first();

    expect($topic)->not->toBeNull();
    expect($topic?->workspace_id)->toBe($workspace->id);
    expect($topic?->slug)->toBe('general');
    expect($otherWorkspace->topics()->count())->toBe(0);

    $response
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('workspace.slug', 'strategy')
            ->where('topic.name', 'General')
            ->where('topic.slug', 'general')
            ->where('topic.posts_count', 0)
            ->where('topic.resource_uri', 'topic-forge://workspaces/strategy/topics/general')
            ->etc()
        );
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
    Post::factory()->for($topic)->count(2)->create();

    $response = TopicForgeServer::actingAs($user)->tool(GetTopicTool::class, [
        'topic_slug' => 'alpha-topic',
    ]);

    $response
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('workspace.slug', 'strategy')
            ->where('topic.slug', 'alpha-topic')
            ->where('topic.posts_count', 2)
            ->where('topic.resource_uri', 'topic-forge://workspaces/strategy/topics/alpha-topic')
            ->etc()
        );
});

test('list agents returns workspace agents with latest versions', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $otherWorkspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

    $agent = Agent::factory()->for($workspace)->create([
        'name' => 'Research Agent',
        'slug' => 'research-agent',
    ]);
    AgentVersion::factory()->for($agent)->create([
        'version' => 3,
        'model' => 'o4-mini',
    ]);

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
            ->where('agents.0.latest_version', 3)
            ->where('agents.0.latest_model', 'o4-mini')
            ->where('agents.0.resource_uri', 'topic-forge://workspaces/strategy/agents/research-agent')
            ->etc()
        );
});

test('create agent creates an agent with an initial version in the current workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $otherWorkspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

    $response = TopicForgeServer::actingAs($user)->tool(CreateAgentTool::class, [
        'name' => 'Research Agent',
        'provider' => Provider::OpenAI->value,
        'model' => 'o4-mini',
        'reasoning_effort' => ReasoningEffort::Low->value,
        'prompt' => 'Research the latest context.',
    ]);

    $agent = $workspace->agents()->where('name', 'Research Agent')->first();

    expect($agent)->not->toBeNull();
    expect($agent?->workspace_id)->toBe($workspace->id);
    expect($otherWorkspace->agents()->count())->toBe(0);
    expect($agent?->versions()->count())->toBe(1);

    $version = $agent?->versions()->first();

    expect($version?->provider)->toBe(Provider::OpenAI);
    expect($version?->model)->toBe('o4-mini');
    expect($version?->reasoning_effort)->toBe(ReasoningEffort::Low);
    expect($version?->prompt)->toBe('Research the latest context.');

    $response
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('workspace.slug', 'strategy')
            ->where('agent.name', 'Research Agent')
            ->where('agent.slug', 'research-agent')
            ->where('agent.resource_uri', 'topic-forge://workspaces/strategy/agents/research-agent')
            ->where('latest_version.version', 1)
            ->where('latest_version.provider', 'openai')
            ->where('latest_version.model', 'o4-mini')
            ->where('latest_version.reasoning_effort', 'low')
            ->where('latest_version.prompt', 'Research the latest context.')
            ->etc()
        );
});

test('get agent returns version history for an accessible workspace', function () {
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
        'version' => 1,
        'model' => 'claude-sonnet-4-6',
        'prompt' => 'First prompt',
    ]);
    AgentVersion::factory()->for($agent)->create([
        'version' => 2,
        'model' => 'o4-mini',
        'prompt' => 'Second prompt',
    ]);
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
            ->where('versions.0.version', 2)
            ->where('versions.0.model', 'o4-mini')
            ->where('versions.0.prompt', 'Second prompt')
            ->where('versions.1.version', 1)
            ->etc()
        );
});

test('update agent renames the agent and creates a new version in the current workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $otherWorkspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

    $agent = Agent::factory()->for($workspace)->create([
        'name' => 'Research Agent',
        'slug' => 'research-agent',
    ]);
    AgentVersion::factory()->for($agent)->create([
        'version' => 1,
        'provider' => Provider::OpenAI,
        'model' => 'o3-mini',
        'reasoning_effort' => ReasoningEffort::Medium,
        'prompt' => 'Old prompt.',
    ]);
    Agent::factory()->for($otherWorkspace)->create([
        'name' => 'Other Agent',
        'slug' => 'other-agent',
    ]);

    $response = TopicForgeServer::actingAs($user)->tool(UpdateAgentTool::class, [
        'agent_slug' => 'research-agent',
        'name' => 'Updated Agent',
        'model' => 'o4-mini',
        'prompt' => 'New prompt.',
    ]);

    $agent->refresh();

    expect($agent->name)->toBe('Updated Agent');
    expect($agent->slug)->toBe('updated-agent');
    expect($agent->versions()->count())->toBe(2);
    expect($otherWorkspace->agents()->where('slug', 'other-agent')->exists())->toBeTrue();

    $version = $agent->latestVersion()->first();

    expect($version?->version)->toBe(2);
    expect($version?->provider)->toBe(Provider::OpenAI);
    expect($version?->model)->toBe('o4-mini');
    expect($version?->reasoning_effort)->toBe(ReasoningEffort::Medium);
    expect($version?->prompt)->toBe('New prompt.');

    $response
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('workspace.slug', 'strategy')
            ->where('agent.name', 'Updated Agent')
            ->where('agent.slug', 'updated-agent')
            ->where('agent.resource_uri', 'topic-forge://workspaces/strategy/agents/updated-agent')
            ->where('latest_version.version', 2)
            ->where('latest_version.provider', 'openai')
            ->where('latest_version.model', 'o4-mini')
            ->where('latest_version.reasoning_effort', 'medium')
            ->where('latest_version.prompt', 'New prompt.')
            ->etc()
        );
});

test('list posts returns topic posts in feed order', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'slug' => 'alpha-topic',
    ]);
    Post::factory()->for($topic)->create([
        'body' => 'Zulu Note',
        'status' => PostStatus::Published,
    ]);
    Post::factory()->for($topic)->create([
        'body' => 'Alpha Draft',
        'status' => PostStatus::Draft,
    ]);

    $response = TopicForgeServer::actingAs($user)->tool(ListPostsTool::class, [
        'topic_slug' => 'alpha-topic',
    ]);

    $response
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('workspace.slug', 'strategy')
            ->where('topic.slug', 'alpha-topic')
            ->where('topic.resource_uri', 'topic-forge://workspaces/strategy/topics/alpha-topic')
            ->where('posts.0.preview', 'Zulu Note')
            ->where('posts.0.status', 'published')
            ->where('posts.1.preview', 'Alpha Draft')
            ->etc()
        );
});

test('list posts includes sender context', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'slug' => 'alpha-topic',
    ]);
    $senderPrincipal = $workspace->principalForUser($user);

    Post::factory()->for($topic)->create([
        'body' => 'Topic Request',
        'status' => PostStatus::Published,
        'sender_principal_id' => $senderPrincipal->id,
    ]);

    $response = TopicForgeServer::actingAs($user)->tool(ListPostsTool::class, [
        'topic_slug' => 'alpha-topic',
    ]);

    $response
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('posts.0.preview', 'Topic Request')
            ->where('posts.0.sender.type', 'user')
            ->where('posts.0.sender.name', $user->name)
            ->etc()
        );
});

test('get post returns the post body and attachment metadata', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'name' => 'Alpha Topic',
        'slug' => 'alpha-topic',
    ]);
    $post = Post::factory()->for($topic)->create([
        'body' => 'Alpha Draft',
        'status' => PostStatus::Draft,
        'sender_principal_id' => $workspace->principalForUser($user)->id,
    ]);
    Attachment::factory()->for($post)->create([
        'filename' => 'report.pdf',
        'mime_type' => 'application/pdf',
        'size' => 4096,
    ]);

    $response = TopicForgeServer::actingAs($user)->tool(GetPostTool::class, [
        'topic_slug' => 'alpha-topic',
        'post_ulid' => $post->ulid,
    ]);

    $response
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('workspace.slug', 'strategy')
            ->where('topic.slug', 'alpha-topic')
            ->where('topic.resource_uri', 'topic-forge://workspaces/strategy/topics/alpha-topic')
            ->where('post.ulid', $post->ulid)
            ->where('post.sender.name', $user->name)
            ->where('post.body', 'Alpha Draft')
            ->where('post.resource_uri', "topic-forge://workspaces/strategy/topics/alpha-topic/posts/{$post->ulid}")
            ->where('attachments.0.filename', 'report.pdf')
            ->where('attachments.0.mime_type', 'application/pdf')
            ->where('attachments.0.size', 4096)
            ->etc()
        );
});

test('list agent tasks returns post-derived work for an agent', function () {
    Queue::fake();

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
    $post = Post::factory()->for($topic)->create([
        'status' => PostStatus::Published,
        'sender_principal_id' => $workspace->principalForUser($user)->id,
        'body' => '@research-agent Please summarize.',
    ]);
    $task = AgentTask::query()->whereBelongsTo($post)->first();

    $response = TopicForgeServer::actingAs($user)->tool(ListAgentTasksTool::class, [
        'agent_slug' => 'research-agent',
    ]);

    $response
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('workspace.slug', 'strategy')
            ->where('agent.slug', 'research-agent')
            ->where('agent.tasks_resource_uri', 'topic-forge://workspaces/strategy/agents/research-agent/tasks')
            ->where('tasks.0.id', $task->id)
            ->where('tasks.0.status', 'pending')
            ->where('tasks.0.event_type', AgentTask::EventPostMentioned)
            ->where('tasks.0.post.ulid', $post->ulid)
            ->where('tasks.0.resource_uri', "topic-forge://workspaces/strategy/agents/research-agent/tasks/{$task->id}")
            ->etc()
        );
});

test('get agent task returns full post context for agent work', function () {
    Queue::fake();

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
    $post = Post::factory()->for($topic)->create([
        'status' => PostStatus::Published,
        'sender_principal_id' => $workspace->principalForUser($user)->id,
        'body' => '@research-agent Please summarize.',
    ]);
    $task = AgentTask::query()->whereBelongsTo($post)->first();

    $response = TopicForgeServer::actingAs($user)->tool(GetAgentTaskTool::class, [
        'agent_slug' => 'research-agent',
        'task_id' => $task->id,
    ]);

    $response
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('workspace.slug', 'strategy')
            ->where('agent.slug', 'research-agent')
            ->where('task.id', $task->id)
            ->where('task.post.ulid', $post->ulid)
            ->where('task.post.body', '@research-agent Please summarize.')
            ->etc()
        );
});

test('create post creates a draft by default', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'slug' => 'alpha-topic',
    ]);

    $response = TopicForgeServer::actingAs($user)->tool(CreatePostTool::class, [
        'topic_slug' => 'alpha-topic',
        'body' => 'Created through the MCP server.',
    ]);

    $post = $topic->posts()->where('body', 'Created through the MCP server.')->first();

    expect($post)->not->toBeNull();
    expect($post?->status)->toBe(PostStatus::Draft);

    $response
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('workspace.slug', 'strategy')
            ->where('topic.slug', 'alpha-topic')
            ->where('topic.resource_uri', 'topic-forge://workspaces/strategy/topics/alpha-topic')
            ->where('post.preview', 'Created through the MCP server.')
            ->where('post.status', 'draft')
            ->where('post.resource_uri', "topic-forge://workspaces/strategy/topics/alpha-topic/posts/{$post->ulid}")
            ->etc()
        );
});

test('create post dispatches mentioned agent task once when published', function () {
    Queue::fake();

    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'slug' => 'alpha-topic',
    ]);
    Agent::factory()->for($workspace)->create([
        'name' => 'Research Agent',
    ]);

    TopicForgeServer::actingAs($user)->tool(CreatePostTool::class, [
        'topic_slug' => 'alpha-topic',
        'body' => '@research-agent Please summarize.',
        'status' => PostStatus::Published->value,
    ])->assertOk();

    $post = $topic->posts()->where('body', '@research-agent Please summarize.')->sole();
    $task = AgentTask::query()->whereBelongsTo($post)->sole();

    Queue::assertPushed(ProcessAgentTask::class, fn (ProcessAgentTask $job): bool => $job->task->is($task));
    Queue::assertPushedTimes(ProcessAgentTask::class, 1);
});

test('delete post soft deletes an own post and clears derived records', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'slug' => 'alpha-topic',
    ]);
    $agent = Agent::factory()->for($workspace)->create([
        'slug' => 'research-agent',
    ]);
    $post = Post::factory()->for($topic)->create([
        'body' => 'Please summarize.',
        'status' => PostStatus::Published,
        'sender_principal_id' => $workspace->principalForUser($user)->id,
    ]);
    $attachment = Attachment::factory()->for($post)->create();
    AgentTask::factory()->for($agent)->for($post)->create();

    expect(AgentTask::query()->whereBelongsTo($post)->count())->toBe(1);

    $response = TopicForgeServer::actingAs($user)->tool(DeletePostTool::class, [
        'topic_slug' => 'alpha-topic',
        'post_ulid' => $post->ulid,
    ]);

    $response
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('workspace.slug', 'strategy')
            ->where('topic.slug', 'alpha-topic')
            ->where('post.ulid', $post->ulid)
            ->where('post.preview', 'Please summarize.')
            ->where('deleted', true)
            ->etc()
        );

    expect(Post::query()->find($post->id))->toBeNull()
        ->and(Post::withTrashed()->find($post->id)?->trashed())->toBeTrue()
        ->and(Attachment::withTrashed()->find($attachment->id)?->trashed())->toBeTrue()
        ->and(AgentTask::query()->where('post_id', $post->id)->exists())->toBeFalse();
});

test('delete post denies posts sent by another user', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $user->switchWorkspace($workspace);
    [$member, $memberPrincipal] = teamMemberPrincipal($user, $workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'slug' => 'alpha-topic',
    ]);
    $post = Post::factory()->for($topic)->create([
        'sender_principal_id' => $memberPrincipal->id,
    ]);

    $response = TopicForgeServer::actingAs($user)->tool(DeletePostTool::class, [
        'topic_slug' => 'alpha-topic',
        'post_ulid' => $post->ulid,
    ]);

    $response->assertHasErrors([
        'Only the post sender can delete this post.',
    ]);

    expect($post->fresh())->not->toBeNull()
        ->and($member->exists)->toBeTrue();
});

test('update post changes body and status of an own post', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'slug' => 'alpha-topic',
    ]);
    $post = Post::factory()->for($topic)->create([
        'body' => 'Original body.',
        'status' => PostStatus::Draft,
        'sender_principal_id' => $workspace->principalForUser($user)->id,
    ]);

    $response = TopicForgeServer::actingAs($user)->tool(UpdatePostTool::class, [
        'topic_slug' => 'alpha-topic',
        'post_ulid' => $post->ulid,
        'body' => 'Updated body.',
        'status' => PostStatus::Published->value,
    ]);

    $response
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('workspace.slug', 'strategy')
            ->where('topic.slug', 'alpha-topic')
            ->where('post.ulid', $post->ulid)
            ->where('post.body', 'Updated body.')
            ->where('post.status', 'published')
            ->etc()
        );

    expect($post->fresh()->body)->toBe('Updated body.')
        ->and($post->fresh()->status)->toBe(PostStatus::Published);
});

test('update post denies editing a post sent by another user', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $user->switchWorkspace($workspace);
    [, $memberPrincipal] = teamMemberPrincipal($user, $workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'slug' => 'alpha-topic',
    ]);
    $post = Post::factory()->for($topic)->create([
        'body' => 'Someone else wrote this.',
        'sender_principal_id' => $memberPrincipal->id,
    ]);

    $response = TopicForgeServer::actingAs($user)->tool(UpdatePostTool::class, [
        'topic_slug' => 'alpha-topic',
        'post_ulid' => $post->ulid,
        'body' => 'Overwritten.',
    ]);

    $response->assertHasErrors([
        'Only the original sender can edit this post.',
    ]);

    expect($post->fresh()->body)->toBe('Someone else wrote this.');
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
    $post = Post::factory()->for($topic)->create([
        'status' => PostStatus::Draft,
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
        ->assertSee('"preview":"Draft body"')
        ->assertSee('"resource_uri":"topic-forge://workspaces/strategy/topics/alpha-topic/posts/'.$post->ulid.'"')
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
    Post::factory()->for($topic)->count(2)->create();

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
        ->assertSee('"posts_count":2')
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
    Post::factory()->for($topic)->count(2)->create();

    app('auth')->guard()->setUser($user);
    app('auth')->shouldUse(null);

    $response = topicForgeServerMethodResponse('resources/read', [
        'uri' => 'topic-forge://workspaces/strategy/topics',
    ]);

    expect($response['result']['contents'][0]['uri'])->toBe('topic-forge://workspaces/strategy/topics');
    expect($response['result']['contents'][0]['text'])->toContain('"slug":"strategy"');
    expect($response['result']['contents'][0]['text'])->toContain('"slug":"alpha-topic"');
    expect($response['result']['contents'][0]['text'])->toContain('"posts_count":2');
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
        ->assertSee('"resource_uri":"topic-forge://workspaces/strategy/agents/research-agent"');
});

test('topic posts resource returns posts for a topic by uri template', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'name' => 'Alpha Topic',
        'slug' => 'alpha-topic',
    ]);
    Post::factory()->for($topic)->create([
        'status' => PostStatus::Published,
        'body' => 'Ready to ship.',
    ]);
    Post::factory()->for($topic)->create([
        'body' => 'Alpha Draft',
        'status' => PostStatus::Draft,
    ]);

    $resource = new class(app(TopicForgeContext::class)) extends TopicPostsResource
    {
        public function uri(): string
        {
            return 'topic-forge://workspaces/strategy/topics/alpha-topic/posts';
        }
    };

    $response = TopicForgeServer::actingAs($user)->resource($resource);

    $response
        ->assertOk()
        ->assertSee('"slug":"alpha-topic"')
        ->assertSee('"preview":"Alpha Draft"')
        ->assertSee('"preview":"Ready to ship."');
});

test('post resource returns post context by uri template', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $user->switchWorkspace($workspace);

    $topic = Topic::factory()->for($workspace)->create([
        'name' => 'Alpha Topic',
        'slug' => 'alpha-topic',
    ]);
    $post = Post::factory()->for($topic)->create([
        'status' => PostStatus::Draft,
        'body' => 'Draft body',
    ]);
    Attachment::factory()->for($post)->create([
        'filename' => 'report.pdf',
        'mime_type' => 'application/pdf',
        'size' => 4096,
    ]);

    $resource = new class(app(TopicForgeContext::class)) extends PostResource
    {
        public function uri(): string
        {
            return 'topic-forge://workspaces/strategy/topics/alpha-topic/posts/'.$GLOBALS['postResourceUlid'];
        }
    };

    $GLOBALS['postResourceUlid'] = $post->ulid;

    $response = TopicForgeServer::actingAs($user)->resource($resource);

    $response
        ->assertOk()
        ->assertSee('"ulid":"'.$post->ulid.'"')
        ->assertSee('"resource_uri":"topic-forge://workspaces/strategy/topics/alpha-topic/posts/'.$post->ulid.'"')
        ->assertSee('"filename":"report.pdf"');
});

test('agent tasks resource returns queued work by uri template', function () {
    Queue::fake();

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
    $post = Post::factory()->for($topic)->create([
        'body' => '@research-agent Agent Request',
        'status' => PostStatus::Published,
        'sender_principal_id' => $workspace->principalForUser($user)->id,
    ]);
    $task = AgentTask::query()->whereBelongsTo($post)->first();

    $resource = new class(app(TopicForgeContext::class)) extends AgentTasksResource
    {
        public function uri(): string
        {
            return 'topic-forge://workspaces/strategy/agents/research-agent/tasks';
        }
    };

    $response = TopicForgeServer::actingAs($user)->resource($resource);

    $response
        ->assertOk()
        ->assertSee('"slug":"research-agent"')
        ->assertSee('"tasks_resource_uri":"topic-forge://workspaces/strategy/agents/research-agent/tasks"')
        ->assertSee('"id":'.$task->id)
        ->assertSee('"ulid":"'.$post->ulid.'"')
        ->assertSee('"resource_uri":"topic-forge://workspaces/strategy/agents/research-agent/tasks/'.$task->id.'"');
});

test('agent task resource returns queued work by uri template', function () {
    Queue::fake();

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
    $post = Post::factory()->for($topic)->create([
        'status' => PostStatus::Published,
        'sender_principal_id' => $workspace->principalForUser($user)->id,
        'body' => '@research-agent Please summarize.',
    ]);
    $task = AgentTask::query()->whereBelongsTo($post)->first();

    $resource = new class(app(TopicForgeContext::class), $task->id) extends AgentTaskResource
    {
        public function __construct(TopicForgeContext $context, private int $taskId)
        {
            parent::__construct($context);
        }

        public function uri(): string
        {
            return "topic-forge://workspaces/strategy/agents/research-agent/tasks/{$this->taskId}";
        }
    };

    $response = TopicForgeServer::actingAs($user)->resource($resource);

    $response
        ->assertOk()
        ->assertSee('"id":'.$task->id)
        ->assertSee('"body":"@research-agent Please summarize."')
        ->assertSee('"name":"Research Agent"');
});

test('agent resource returns agent context by uri template', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create([
        'slug' => 'strategy',
    ]);
    $user->switchWorkspace($workspace);

    $agent = Agent::factory()->for($workspace)->create([
        'name' => 'Research Agent',
        'slug' => 'research-agent',
    ]);
    AgentVersion::factory()->for($agent)->create([
        'version' => 2,
        'model' => 'o4-mini',
        'prompt' => 'Second prompt',
    ]);
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
        ->assertSee('"tasks_resource_uri":"topic-forge://workspaces/strategy/agents/research-agent/tasks"');
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
        ->assertSee('topic-forge://workspaces/{workspace}/topics/{topic}/posts/{post}');
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

    $posts = collect(explode("\n", $output))
        ->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR));

    expect($posts)->toHaveCount(2);
    expect($posts[1]['error']['message'])->toBe('You must be authenticated to use the Topic Forge MCP server.');
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

    $posts = collect(explode("\n", $output))
        ->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR));

    expect($posts)->toHaveCount(2);
    expect($posts[0]['result']['serverInfo']['name'])->toBe('Topic Forge Server');
    expect($posts[1]['error']['message'])->toBe('The configured MCP local auth user could not be resolved.');
});

test('topic forge server lists topic, post, and agent resource templates', function () {
    $response = topicForgeServerMethodResponse('resources/templates/list');

    expect($response['result']['resourceTemplates'])->toHaveCount(8);

    expect(collect($response['result']['resourceTemplates'])->contains(
        fn (array $resource): bool => $resource['uriTemplate'] === 'topic-forge://workspaces/{workspace}/topics'
    ))->toBeTrue();

    expect(collect($response['result']['resourceTemplates'])->contains(
        fn (array $resource): bool => $resource['uriTemplate'] === 'topic-forge://workspaces/{workspace}/agents'
    ))->toBeTrue();

    expect(collect($response['result']['resourceTemplates'])->contains(
        fn (array $resource): bool => $resource['uriTemplate'] === 'topic-forge://workspaces/{workspace}/topics/{topic}/posts'
    ))->toBeTrue();

    expect(collect($response['result']['resourceTemplates'])->contains(
        fn (array $resource): bool => $resource['name'] === 'topic-resource'
            && $resource['uriTemplate'] === 'topic-forge://workspaces/{workspace}/topics/{topic}'
    ))->toBeTrue();

    expect(collect($response['result']['resourceTemplates'])->contains(
        fn (array $resource): bool => $resource['name'] === 'post-resource'
            && $resource['uriTemplate'] === 'topic-forge://workspaces/{workspace}/topics/{topic}/posts/{post}'
    ))->toBeTrue();

    expect(collect($response['result']['resourceTemplates'])->contains(
        fn (array $resource): bool => $resource['name'] === 'agent-resource'
            && $resource['uriTemplate'] === 'topic-forge://workspaces/{workspace}/agents/{agent}'
    ))->toBeTrue();

    expect(collect($response['result']['resourceTemplates'])->contains(
        fn (array $resource): bool => $resource['name'] === 'agent-tasks-resource'
            && $resource['uriTemplate'] === 'topic-forge://workspaces/{workspace}/agents/{agent}/tasks'
    ))->toBeTrue();

    expect(collect($response['result']['resourceTemplates'])->contains(
        fn (array $resource): bool => $resource['name'] === 'agent-task-resource'
            && $resource['uriTemplate'] === 'topic-forge://workspaces/{workspace}/agents/{agent}/tasks/{task}'
    ))->toBeTrue();
});

test('workspace access is denied outside the current team scope', function () {
    [$user, $workspace] = userWithWorkspace();

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

test('list-repos tool returns workspace repositories', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

    WorkspaceRepository::factory()->for($workspace)->create([
        'name' => 'api-service',
        'url' => 'git@github.com:org/api-service.git',
        'branch' => 'main',
    ]);

    TopicForgeServer::actingAs($user)->tool(ListReposTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('workspace.slug', $workspace->slug)
            ->where('repositories.0.name', 'api-service')
            ->where('repositories.0.url', 'git@github.com:org/api-service.git')
            ->where('repositories.0.branch', 'main')
            ->has('repositories.0.cloned')
            ->etc()
        );
});

test('run-git-command tool returns error when repo is not cloned', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

    WorkspaceRepository::factory()->for($workspace)->create(['name' => 'api-service']);

    TopicForgeServer::actingAs($user)->tool(RunGitCommandTool::class, [
        'repo' => 'api-service',
        'command' => 'git log --oneline -1',
    ])->assertHasErrors(['has not been cloned']);
});

test('run-git-command tool executes command in cloned repo directory', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user->currentTeam)->create();
    $user->switchWorkspace($workspace);

    $bare = sys_get_temp_dir().'/bare-'.uniqid();
    exec("git init --bare {$bare} 2>&1");
    $tmp = sys_get_temp_dir().'/clone-'.uniqid();
    exec("git clone {$bare} {$tmp} 2>&1");
    file_put_contents("{$tmp}/README.md", '# test');
    exec("git -C {$tmp} config user.email test@test.com && git -C {$tmp} config user.name T && git -C {$tmp} add . && git -C {$tmp} commit -m init && git -C {$tmp} push origin HEAD:main 2>&1");
    exec("rm -rf {$tmp}");

    $repo = WorkspaceRepository::factory()->for($workspace)->create([
        'name' => 'myrepo',
        'url' => $bare,
        'branch' => 'main',
        'auth_type' => 'ssh',
    ]);

    (new GitRepositoryService($repo))->sync();

    TopicForgeServer::actingAs($user)->tool(RunGitCommandTool::class, [
        'repo' => 'myrepo',
        'command' => 'git log --oneline -1',
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('repo', 'myrepo')
            ->where('exit_code', 0)
            ->etc()
        )
        ->assertSee('init');

    exec("rm -rf {$bare}");
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
