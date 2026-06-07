<?php

use App\Actions\Agents\ExecuteAgentTask;
use App\Enums\AgentTaskStatus;
use App\Enums\PostStatus;
use App\Enums\Provider;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\AgentVersion;
use App\Models\Post;
use App\Models\Thread;
use App\Models\Topic;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Ai;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function () {
    [$this->user, $this->workspace] = userWithWorkspace();
    $this->topic = Topic::factory()->for($this->workspace)->create();
    $this->senderPrincipal = $this->workspace->principalForUser($this->user);

    Queue::fake();
});

test('it executes a pending agent task through an anonymous laravel ai agent', function () {
    Ai::fakeAgent(AnonymousAgent::class, ['The agent response.'])->preventStrayPrompts();

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

    Ai::assertAgentWasPrompted(AnonymousAgent::class, function (AgentPrompt $prompt): bool {
        return $prompt->prompt === '@researcher Find the latest internal context.'
            && $prompt->model === 'gemini-2.5-flash'
            && $prompt->provider()->name() === 'gemini'
            && $prompt->agent->instructions() === 'Answer as a concise researcher.';
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
    Ai::fakeAgent(AnonymousAgent::class)->preventStrayPrompts();

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

    Ai::assertAgentNeverPrompted(AnonymousAgent::class);

    expect($task->fresh()->status)->toBe(AgentTaskStatus::Failed)
        ->and($task->fresh()->status_post_id)->toBe($statusPost->id)
        ->and($statusPost->fresh()->body)->toBe('Researcher failed: Agent does not have a version to execute.')
        ->and($task->fresh()->attempts)->toBe(1)
        ->and($task->fresh()->locked_at)->toBeNull()
        ->and($task->fresh()->last_error)->toBe('Agent does not have a version to execute.');
});

test('it replies in the existing thread when the mentioned post is already threaded', function () {
    Ai::fakeAgent(AnonymousAgent::class, ['Threaded response.'])->preventStrayPrompts();

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
    Ai::fakeAgent(AnonymousAgent::class)->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    AgentVersion::factory()->for($agent)->create();
    $post = Post::factory()->for($this->topic)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => '@researcher Draft request.',
        'status' => PostStatus::Draft,
    ]);

    expect(AgentTask::query()->whereBelongsTo($post)->count())->toBe(0);

    Ai::assertAgentNeverPrompted(AnonymousAgent::class);
});
