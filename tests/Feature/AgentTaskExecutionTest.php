<?php

use App\Actions\Agents\ExecuteAgentTask;
use App\Ai\Agents\ExplicateMentionAgent;
use App\Ai\Tools\ExplicateToolFactory;
use App\Enums\AgentTaskStatus;
use App\Enums\PostStatus;
use App\Enums\Provider;
use App\Enums\ReasoningEffort;
use App\Jobs\ProcessAgentTask;
use App\Mcp\ExplicateUris;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\AgentVersion;
use App\Models\Post;
use App\Models\ProviderKey;
use App\Models\Thread;
use App\Models\Topic;
use App\Models\Workspace;
use App\Services\AiProviderKeyService;
use Illuminate\Support\Facades\Queue;

afterEach(function () {
    $workspacesDir = storage_path('app/workspaces');
    if (is_dir($workspacesDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($workspacesDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getRealPath()) : unlink($entry->getRealPath());
        }
        rmdir($workspacesDir);
    }
});
use Laravel\Ai\Ai;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Tools\Request as AiToolRequest;
use Laravel\Ai\Tools\ToolNameResolver;

beforeEach(function () {
    [$this->user, $this->workspace] = userWithWorkspace();
    $this->topic = Topic::factory()->for($this->workspace)->create();
    $this->senderPrincipal = $this->workspace->principalForUser($this->user);

    Queue::fake();
});

test('it executes a pending agent task through the topic forge mention agent', function () {
    Ai::fakeAgent(ExplicateMentionAgent::class, ['Researcher (@researcher): The agent response.'])->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    AgentVersion::factory()->for($agent)->create([
        'provider' => Provider::Gemini,
        'model' => 'gemini-2.5-flash',
        'prompt' => 'Answer as a concise researcher.',
    ]);

    $post = Post::factory()->for(Thread::factory()->forTopic($this->topic))->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => '@researcher Find the latest internal context.',
        'status' => PostStatus::Published,
    ]);

    $task = AgentTask::query()->whereBelongsTo($post)->sole();
    $statusPost = $task->statusPost;

    $reply = app(ExecuteAgentTask::class)->handle($task);

    Ai::assertAgentWasPrompted(ExplicateMentionAgent::class, function (AgentPrompt $prompt): bool {
        $toolNames = collect($prompt->agent->tools())
            ->map(fn ($tool): string => ToolNameResolver::resolve($tool))
            ->all();

        return $prompt->prompt === '@researcher Find the latest internal context.'
            && $prompt->model === 'gemini-2.5-flash'
            && $prompt->provider()->name() === 'gemini'
            && str_starts_with($prompt->agent->instructions(), 'Answer as a concise researcher.')
            && str_contains($prompt->agent->instructions(), 'You are Researcher (@researcher).')
            && str_contains($prompt->agent->instructions(), 'When the conversation mentions @researcher')
            && str_contains($prompt->agent->instructions(), 'Do not prefix your reply with your name')
            && str_contains($prompt->agent->instructions(), 'Explicate artifact policy:')
            && str_contains($prompt->agent->instructions(), 'Use the workspace filesystem tools for substantial artifacts')
            && str_contains($prompt->agent->instructions(), 'reference that path in your reply')
            && str_contains($prompt->agent->instructions(), 'file tool response includes file.path and file.dashboard_url')
            && str_contains($prompt->agent->instructions(), 'replacing both values with the exact strings returned by the tool')
            && str_contains($prompt->agent->instructions(), 'Never write placeholder hrefs such as dashboard_url')
            && str_contains($prompt->agent->instructions(), 'do not link artifact titles to the current thread or dashboard')
            && str_contains($prompt->agent->instructions(), 'Explicate task list policy:')
            && str_contains($prompt->agent->instructions(), 'Maintain a private task list with the task-list tool')
            && in_array('list-files', $toolNames, true)
            && in_array('write-file', $toolNames, true)
            && in_array('create-post', $toolNames, true)
            && in_array('task-list', $toolNames, true)
            && ! in_array('switch-workspace', $toolNames, true);
    });

    expect($reply)->not->toBeNull()
        ->and($reply->id)->toBe($statusPost->id)
        ->and($reply->body)->toBe('The agent response.')
        ->and($reply->status)->toBe(PostStatus::Published)
        ->and($reply->sender_principal_id)->toBe($this->workspace->principalForAgent($agent)->id)
        ->and($reply->thread_id)->toBe($post->thread_id)
        ->and($post->fresh()->thread_id)->not->toBeNull()
        ->and($reply->thread?->topic->is($this->topic))->toBeTrue()
        ->and($reply->thread?->posts()->count())->toBe(2)
        ->and($task->fresh()->status)->toBe(AgentTaskStatus::Completed)
        ->and($task->fresh()->status_post_id)->toBe($statusPost->id)
        ->and($task->fresh()->attempts)->toBe(1)
        ->and($task->fresh()->locked_at)->toBeNull()
        ->and($task->fresh()->last_error)->toBeNull();

    $post->update(['body' => '@researcher Add one more detail.']);

    expect($reply->fresh()->body)->toBe('The agent response.');
});

test('it injects the decrypted team provider key before prompting the agent', function () {
    config(['ai.providers.openai.key' => '']);

    ProviderKey::create([
        'team_id' => $this->user->currentTeam->id,
        'provider' => Provider::OpenAI,
        'api_key' => 'sk-team-openai-key',
    ]);

    Ai::fakeAgent(ExplicateMentionAgent::class, function (string $prompt, $attachments, $provider, string $model): string {
        expect($provider->name())->toBe('openai')
            ->and($provider->providerCredentials()['key'])->toBe('sk-team-openai-key')
            ->and($model)->toBe('gpt-5.4-mini');

        return 'OpenAI response.';
    })->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    AgentVersion::factory()->for($agent)->create([
        'provider' => Provider::OpenAI,
        'model' => 'gpt-5.4-mini',
    ]);

    $post = Post::factory()->for(Thread::factory()->forTopic($this->topic))->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => '@researcher Use the configured OpenAI key.',
        'status' => PostStatus::Published,
    ]);

    $task = AgentTask::query()->whereBelongsTo($post)->sole();

    app(ExecuteAgentTask::class)->handle($task);
});

test('process agent task failed hook marks the task failed visibly', function () {
    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    AgentVersion::factory()->for($agent)->create([
        'provider' => Provider::OpenAI,
        'model' => 'gpt-5.5',
    ]);
    $post = Post::factory()->for(Thread::factory()->forTopic($this->topic))->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => '@researcher Do work.',
        'status' => PostStatus::Published,
    ]);
    $task = AgentTask::query()->whereBelongsTo($post)->sole();
    $task->forceFill([
        'status' => AgentTaskStatus::Processing,
        'locked_at' => now(),
        'attempts' => 1,
    ])->save();
    $statusPost = $task->syncStatusPost();

    (new ProcessAgentTask($task))->failed(new RuntimeException('Worker timeout.'));

    $task->refresh();

    expect($task->status)->toBe(AgentTaskStatus::Failed)
        ->and($task->locked_at)->toBeNull()
        ->and($task->last_error)->toBe('Worker timeout.')
        ->and($statusPost->fresh()->body)->toBe('Researcher failed: Worker timeout.');
});

test('stale processing agent tasks are marked failed by the cleanup command', function () {
    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    AgentVersion::factory()->for($agent)->create([
        'provider' => Provider::OpenAI,
        'model' => 'gpt-5.5',
    ]);
    $post = Post::factory()->for(Thread::factory()->forTopic($this->topic))->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => '@researcher Do work.',
        'status' => PostStatus::Published,
    ]);
    $task = AgentTask::query()->whereBelongsTo($post)->sole();
    $task->forceFill([
        'status' => AgentTaskStatus::Processing,
        'locked_at' => now()->subMinutes(11),
        'attempts' => 1,
    ])->save();
    $statusPost = $task->syncStatusPost();

    $this->artisan('agent-tasks:fail-stale')
        ->expectsOutputToContain('Marked 1 stale agent task(s) as failed.')
        ->assertSuccessful();

    $task->refresh();

    expect($task->status)->toBe(AgentTaskStatus::Failed)
        ->and($task->locked_at)->toBeNull()
        ->and($task->last_error)->toBe('Agent task timed out or the worker exited before completing.')
        ->and($statusPost->fresh()->body)->toBe('Researcher failed: Agent task timed out or the worker exited before completing.');
});

test('it passes the agent version reasoning effort to openai provider options', function () {
    config(['ai.providers.openai.key' => '']);

    ProviderKey::create([
        'team_id' => $this->user->currentTeam->id,
        'provider' => Provider::OpenAI,
        'api_key' => 'sk-team-openai-key',
    ]);

    Ai::fakeAgent(ExplicateMentionAgent::class, ['OpenAI response.'])->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Implementer']);
    AgentVersion::factory()->for($agent)->create([
        'provider' => Provider::OpenAI,
        'model' => 'gpt-5.5',
        'reasoning_effort' => ReasoningEffort::High,
    ]);

    $post = Post::factory()->for(Thread::factory()->forTopic($this->topic))->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => '@implementer Use explicit reasoning.',
        'status' => PostStatus::Published,
    ]);

    $task = AgentTask::query()->whereBelongsTo($post)->sole();

    app(ExecuteAgentTask::class)->handle($task);

    Ai::assertAgentWasPrompted(ExplicateMentionAgent::class, fn (AgentPrompt $prompt): bool => $prompt->agent->providerOptions('openai') === [
        'reasoning' => [
            'effort' => 'high',
        ],
    ]);
});

test('it normalizes nested anthropic beta config while prompting with a workspace key', function () {
    config([
        'ai.providers.anthropic.key' => '',
        'ai.providers.anthropic.anthropic_beta' => [
            ['web-fetch-2025-09-10'],
            ['context-management-2025-06-27'],
        ],
    ]);

    ProviderKey::create([
        'team_id' => $this->user->currentTeam->id,
        'provider' => Provider::Anthropic,
        'api_key' => 'sk-ant-team-key',
    ]);

    $service = app(AiProviderKeyService::class);
    $configuredBeta = null;

    $service->withWorkspaceKey($this->workspace, Provider::Anthropic->value, function () use (&$configuredBeta): void {
        $configuredBeta = config('ai.providers.anthropic.anthropic_beta');
    });

    expect($configuredBeta)->toBe('web-fetch-2025-09-10,context-management-2025-06-27')
        ->and(config('ai.providers.anthropic.anthropic_beta'))->toBe([
            ['web-fetch-2025-09-10'],
            ['context-management-2025-06-27'],
        ]);
});

test('it only exposes MCP tools allowed by the latest agent version', function () {
    Ai::fakeAgent(ExplicateMentionAgent::class, ['Constrained response.'])->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    AgentVersion::factory()->for($agent)->create([
        'provider' => Provider::Gemini,
        'model' => 'gemini-2.5-flash',
        'allowed_tools' => ['get-thread', 'write-file'],
    ]);

    $post = Post::factory()->for(Thread::factory()->forTopic($this->topic))->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => '@researcher Use constrained tools.',
        'status' => PostStatus::Published,
    ]);

    $task = AgentTask::query()->whereBelongsTo($post)->sole();

    app(ExecuteAgentTask::class)->handle($task);

    Ai::assertAgentWasPrompted(ExplicateMentionAgent::class, function (AgentPrompt $prompt): bool {
        $toolNames = collect($prompt->agent->tools())
            ->map(fn ($tool): string => ToolNameResolver::resolve($tool))
            ->all();

        return in_array('task-list', $toolNames, true)
            && in_array('get-thread', $toolNames, true)
            && in_array('write-file', $toolNames, true)
            && ! in_array('create-post', $toolNames, true)
            && ! in_array('list-files', $toolNames, true);
    });
});

test('it marks a task failed when the agent has no executable version', function () {
    Ai::fakeAgent(ExplicateMentionAgent::class)->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    $post = Post::factory()->for(Thread::factory()->forTopic($this->topic))->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => '@researcher Please handle this.',
        'status' => PostStatus::Published,
    ]);

    $task = AgentTask::query()->whereBelongsTo($post)->sole();
    $statusPost = $task->statusPost;
    $exception = null;

    try {
        app(ExecuteAgentTask::class)->handle($task);
    } catch (RuntimeException $exception) {
    }

    expect($exception)->not->toBeNull()
        ->and($exception->getMessage())->toBe('Agent does not have a version to execute.');

    Ai::assertAgentNeverPrompted(ExplicateMentionAgent::class);

    expect($task->fresh()->status)->toBe(AgentTaskStatus::Failed)
        ->and($task->fresh()->status_post_id)->toBe($statusPost->id)
        ->and($statusPost->fresh()->body)->toBe('Researcher failed: Agent does not have a version to execute.')
        ->and($task->fresh()->attempts)->toBe(1)
        ->and($task->fresh()->locked_at)->toBeNull()
        ->and($task->fresh()->last_error)->toBe('Agent does not have a version to execute.');
});

test('it replies in the existing thread when the mentioned post is already threaded', function () {
    Ai::fakeAgent(ExplicateMentionAgent::class, ['Threaded response.'])->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    AgentVersion::factory()->for($agent)->create([
        'provider' => Provider::Gemini,
        'model' => 'gemini-2.5-flash',
    ]);
    $thread = Thread::factory()->forTopic($this->topic)->create();

    $post = Post::factory()->for(Thread::factory()->forTopic($this->topic))->for($thread)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => '@researcher Continue in this thread.',
        'status' => PostStatus::Published,
    ]);

    $task = AgentTask::query()->whereBelongsTo($post)->sole();
    $statusPost = $task->statusPost;

    $reply = app(ExecuteAgentTask::class)->handle($task);

    expect($reply)->not->toBeNull()
        ->and($reply->id)->toBe($statusPost->id)
        ->and($reply->thread_id)->toBe($thread->id)
        ->and($post->fresh()->thread_id)->toBe($thread->id)
        ->and($this->topic->threads()->count())->toBe(1);
});

test('it does not execute mentioned draft posts until they are published', function () {
    Ai::fakeAgent(ExplicateMentionAgent::class)->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    AgentVersion::factory()->for($agent)->create();
    $post = Post::factory()->for(Thread::factory()->forTopic($this->topic))->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => '@researcher Draft request.',
        'status' => PostStatus::Draft,
    ]);

    expect(AgentTask::query()->whereBelongsTo($post)->count())->toBe(0);

    Ai::assertAgentNeverPrompted(ExplicateMentionAgent::class);
});

test('it sends previous thread posts as conversation messages with sender identity', function () {
    Ai::fakeAgent(ExplicateMentionAgent::class, ['Thread context response.'])->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create([
        'name' => 'Specification Writer',
        'slug' => 'specification-writer',
    ]);
    AgentVersion::factory()->for($agent)->create([
        'provider' => Provider::Gemini,
        'model' => 'gemini-2.5-flash',
        'prompt' => 'Write precise specifications.',
    ]);

    $thread = Thread::factory()->forTopic($this->topic)->create([
        'title' => 'TodoMVC specification',
    ]);
    $parentPost = Post::factory()->for($thread)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => 'Please draft the TodoMVC specification.',
        'status' => PostStatus::Published,
    ]);

    Post::factory()->for($thread)->create([
        'sender_principal_id' => $this->workspace->principalForAgent($agent)->id,
        'body' => 'I created an initial outline.',
        'status' => PostStatus::Published,
    ]);

    $mentionPost = Post::factory()->for($thread)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => '@specification-writer Please revise it for acceptance criteria.',
        'status' => PostStatus::Published,
    ]);

    $task = AgentTask::query()->whereBelongsTo($mentionPost)->sole();

    app(ExecuteAgentTask::class)->handle($task);

    $userName = $this->user->name;

    Ai::assertAgentWasPrompted(ExplicateMentionAgent::class, function (AgentPrompt $prompt) use ($userName): bool {
        $messages = collect($prompt->agent->messages());

        return $messages->count() === 2
            && $messages[0]->role->value === 'user'
            && $messages[0]->content === "{$userName}: Please draft the TodoMVC specification."
            && $messages[1]->role->value === 'assistant'
            && $messages[1]->content === 'Specification Writer (@specification-writer): I created an initial outline.'
            && $prompt->prompt === '@specification-writer Please revise it for acceptance criteria.';
    });
});

test('mention agent tools run against the task workspace context', function () {
    $otherWorkspace = Workspace::factory()->for($this->user->currentTeam)->create([
        'name' => 'Other Workspace',
        'slug' => 'other-workspace',
    ]);
    $this->user->switchWorkspace($otherWorkspace);

    $tool = collect(app(ExplicateToolFactory::class)->forAgentTask($this->user, $this->workspace))
        ->first(fn ($tool): bool => ToolNameResolver::resolve($tool) === 'write-file');

    expect($tool)->not->toBeNull();

    $response = $tool->handle(new AiToolRequest([
        'path' => 'notes/context.md',
        'content' => 'Task-local context.',
    ]));

    expect((string) $response)->toContain('notes/context.md');
    expect($this->workspace->filesystem()->exists('notes/context.md'))->toBeTrue();
    expect($otherWorkspace->filesystem()->exists('notes/context.md'))->toBeFalse();
    expect($this->user->fresh()->current_workspace_id)->toBe($otherWorkspace->id);
});

test('mention agent thread tools resolve threads from the task workspace context', function () {
    $otherWorkspace = Workspace::factory()->for($this->user->currentTeam)->create([
        'name' => 'Other Workspace',
        'slug' => 'other-workspace',
    ]);
    $this->user->switchWorkspace($otherWorkspace);

    $thread = Thread::factory()->for($this->workspace)->create([
        'title' => 'Task Thread',
        'slug' => 'task-thread',
    ]);
    Post::factory()->for($thread)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => 'Original request.',
        'status' => PostStatus::Published,
    ]);

    $tool = collect(app(ExplicateToolFactory::class)->forAgentTask($this->user, $this->workspace))
        ->first(fn ($tool): bool => ToolNameResolver::resolve($tool) === 'create-post');

    expect($tool)->not->toBeNull();

    $response = json_decode((string) $tool->handle(new AiToolRequest([
        'thread' => 'task-thread',
        'body' => 'Agent follow-up.',
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($response['workspace']['id'])->toBe($this->workspace->id)
        ->and($response['thread']['slug'])->toBe('task-thread')
        ->and($thread->posts()->where('body', 'Agent follow-up.')->exists())->toBeTrue()
        ->and($otherWorkspace->posts()->where('body', 'Agent follow-up.')->exists())->toBeFalse()
        ->and($this->user->fresh()->current_workspace_id)->toBe($otherWorkspace->id);
});

test('mention agent thread tools accept emitted thread resource references', function () {
    $thread = Thread::factory()->for($this->workspace)->create([
        'title' => 'Task Thread',
        'slug' => 'task-thread',
    ]);
    Post::factory()->for($thread)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => 'Original request.',
        'status' => PostStatus::Published,
    ]);

    $tool = collect(app(ExplicateToolFactory::class)->forAgentTask($this->user, $this->workspace))
        ->first(fn ($tool): bool => ToolNameResolver::resolve($tool) === 'create-post');

    expect($tool)->not->toBeNull();

    foreach ([ExplicateUris::thread($thread), route('dashboard', ['thread' => $thread->slug])] as $threadReference) {
        $response = json_decode((string) $tool->handle(new AiToolRequest([
            'thread' => $threadReference,
            'body' => "Reply via {$threadReference}.",
        ])), true, flags: JSON_THROW_ON_ERROR);

        expect($response['thread']['slug'])->toBe('task-thread')
            ->and($thread->posts()->where('body', "Reply via {$threadReference}.")->exists())->toBeTrue();
    }
});
