<?php

use App\Actions\Agents\ExecuteAgentTask;
use App\Ai\Agents\TopicForgeMentionAgent;
use App\Ai\Tools\TopicForgeToolFactory;
use App\Enums\AgentTaskStatus;
use App\Enums\PostStatus;
use App\Enums\Provider;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\AgentVersion;
use App\Models\Post;
use App\Models\Thread;
use App\Models\Topic;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
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
    Ai::fakeAgent(TopicForgeMentionAgent::class, ['The agent response.'])->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    AgentVersion::factory()->for($agent)->create([
        'provider' => Provider::Gemini,
        'model' => 'gemini-2.5-flash',
        'prompt' => 'Answer as a concise researcher.',
    ]);

    $post = Post::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => '@researcher Find the latest internal context.',
        'status' => PostStatus::Published,
    ]);

    $task = AgentTask::query()->whereBelongsTo($post)->sole();
    $statusPost = $task->statusPost;

    $reply = app(ExecuteAgentTask::class)->handle($task);

    Ai::assertAgentWasPrompted(TopicForgeMentionAgent::class, function (AgentPrompt $prompt): bool {
        $toolNames = collect($prompt->agent->tools())
            ->map(fn ($tool): string => ToolNameResolver::resolve($tool))
            ->all();

        return $prompt->prompt === '@researcher Find the latest internal context.'
            && $prompt->model === 'gemini-2.5-flash'
            && $prompt->provider()->name() === 'gemini'
            && str_starts_with($prompt->agent->instructions(), 'Answer as a concise researcher.')
            && str_contains($prompt->agent->instructions(), 'You are Researcher (@researcher).')
            && str_contains($prompt->agent->instructions(), 'When the conversation mentions @researcher')
            && str_contains($prompt->agent->instructions(), 'Topic Forge artifact policy:')
            && str_contains($prompt->agent->instructions(), 'Use the workspace filesystem tools for substantial artifacts')
            && str_contains($prompt->agent->instructions(), 'reference that path in your reply')
            && str_contains($prompt->agent->instructions(), 'use a Markdown link with the file path as the label')
            && str_contains($prompt->agent->instructions(), 'dashboard_url as the href')
            && in_array('list-files', $toolNames, true)
            && in_array('write-file', $toolNames, true)
            && in_array('create-post', $toolNames, true)
            && ! in_array('switch-workspace', $toolNames, true);
    });

    expect($reply)->not->toBeNull()
        ->and($reply->id)->toBe($statusPost->id)
        ->and($reply->body)->toBe('The agent response.')
        ->and($reply->status)->toBe(PostStatus::Published)
        ->and($reply->sender_principal_id)->toBe($this->workspace->principalForAgent($agent)->id)
        ->and($reply->thread_id)->not->toBeNull()
        ->and($post->fresh()->thread_id)->toBeNull()
        ->and($reply->thread?->parent_post_id)->toBe($post->id)
        ->and($reply->thread?->topic->is($this->topic))->toBeTrue()
        ->and($reply->thread?->posts()->count())->toBe(1)
        ->and($task->fresh()->status)->toBe(AgentTaskStatus::Completed)
        ->and($task->fresh()->status_post_id)->toBe($statusPost->id)
        ->and($task->fresh()->attempts)->toBe(1)
        ->and($task->fresh()->locked_at)->toBeNull()
        ->and($task->fresh()->last_error)->toBeNull();

    $post->update(['body' => '@researcher Add one more detail.']);

    expect($reply->fresh()->body)->toBe('The agent response.');
});

test('it marks a task failed when the agent has no executable version', function () {
    Ai::fakeAgent(TopicForgeMentionAgent::class)->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    $post = Post::factory()->for($this->topic)->create([
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

    Ai::assertAgentNeverPrompted(TopicForgeMentionAgent::class);

    expect($task->fresh()->status)->toBe(AgentTaskStatus::Failed)
        ->and($task->fresh()->status_post_id)->toBe($statusPost->id)
        ->and($statusPost->fresh()->body)->toBe('Researcher failed: Agent does not have a version to execute.')
        ->and($task->fresh()->attempts)->toBe(1)
        ->and($task->fresh()->locked_at)->toBeNull()
        ->and($task->fresh()->last_error)->toBe('Agent does not have a version to execute.');
});

test('it replies in the existing thread when the mentioned post is already threaded', function () {
    Ai::fakeAgent(TopicForgeMentionAgent::class, ['Threaded response.'])->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    AgentVersion::factory()->for($agent)->create([
        'provider' => Provider::Gemini,
        'model' => 'gemini-2.5-flash',
    ]);
    $thread = Thread::factory()->for($this->topic)->create();

    $post = Post::factory()->for($this->topic)->for($thread)->create([
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
    Ai::fakeAgent(TopicForgeMentionAgent::class)->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    AgentVersion::factory()->for($agent)->create();
    $post = Post::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => '@researcher Draft request.',
        'status' => PostStatus::Draft,
    ]);

    expect(AgentTask::query()->whereBelongsTo($post)->count())->toBe(0);

    Ai::assertAgentNeverPrompted(TopicForgeMentionAgent::class);
});

test('it sends previous thread posts as conversation messages with sender identity', function () {
    Ai::fakeAgent(TopicForgeMentionAgent::class, ['Thread context response.'])->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create([
        'name' => 'Specification Writer',
        'slug' => 'specification-writer',
    ]);
    AgentVersion::factory()->for($agent)->create([
        'provider' => Provider::Gemini,
        'model' => 'gemini-2.5-flash',
        'prompt' => 'Write precise specifications.',
    ]);

    $parentPost = Post::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => 'Please draft the TodoMVC specification.',
        'status' => PostStatus::Published,
    ]);
    $thread = Thread::factory()->for($this->topic)->create([
        'parent_post_id' => $parentPost->id,
        'title' => 'TodoMVC specification',
    ]);

    Post::factory()->for($this->topic)->for($thread)->create([
        'sender_principal_id' => $this->workspace->principalForAgent($agent)->id,
        'body' => 'I created an initial outline.',
        'status' => PostStatus::Published,
    ]);

    $mentionPost = Post::factory()->for($this->topic)->for($thread)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => '@specification-writer Please revise it for acceptance criteria.',
        'status' => PostStatus::Published,
    ]);

    $task = AgentTask::query()->whereBelongsTo($mentionPost)->sole();

    app(ExecuteAgentTask::class)->handle($task);

    $userName = $this->user->name;

    Ai::assertAgentWasPrompted(TopicForgeMentionAgent::class, function (AgentPrompt $prompt) use ($userName): bool {
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

    $tool = collect(app(TopicForgeToolFactory::class)->forAgentTask($this->user, $this->workspace))
        ->first(fn ($tool): bool => ToolNameResolver::resolve($tool) === 'write-file');

    expect($tool)->not->toBeNull();

    $response = $tool->handle(new AiToolRequest([
        'path' => 'notes/context.md',
        'content' => 'Task-local context.',
    ]));

    expect((string) $response)->toContain('notes/context.md');
    expect($this->workspace->files()->where('path', 'notes/context.md')->exists())->toBeTrue();
    expect($otherWorkspace->files()->where('path', 'notes/context.md')->exists())->toBeFalse();
    expect($this->user->fresh()->current_workspace_id)->toBe($otherWorkspace->id);
});
