<?php

use App\Actions\Agents\SyncAgentChatReplies;
use App\Ai\Agents\ExplicateAgentRouter;
use App\Enums\AgentTaskStatus;
use App\Enums\PostStatus;
use App\Enums\Provider;
use App\Jobs\ProcessAgentTask;
use App\Jobs\RouteThreadAgentReplies;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\AgentVersion;
use App\Models\Post;
use App\Models\ProviderKey;
use App\Models\Thread;
use App\Models\Topic;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Ai;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function () {
    [$this->user, $this->workspace] = userWithWorkspace();
    $this->topic = Topic::factory()->for($this->workspace)->create();
    $this->senderPrincipal = $this->workspace->principalForUser($this->user);

    Queue::fake();
});

test('mentioning an agent in a published post creates agent work instead of a notification', function () {
    Notification::fake();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);

    $post = Post::factory()->for(Thread::factory()->forTopic($this->topic))->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => '@researcher Find the latest internal context.',
        'status' => PostStatus::Published,
    ]);

    Notification::assertNothingSent();

    $task = AgentTask::query()
        ->whereBelongsTo($agent)
        ->whereBelongsTo($post)
        ->first();

    expect($task)->not->toBeNull()
        ->and($task->event_type)->toBe(AgentTask::EventPostMentioned)
        ->and($task->status)->toBe(AgentTaskStatus::Pending)
        ->and($task->available_at)->not->toBeNull()
        ->and($task->statusPost)->not->toBeNull()
        ->and($task->statusPost->body)->toBe('Researcher queued.')
        ->and($task->statusPost->thread_id)->toBe($post->thread_id)
        ->and($post->fresh()->thread_id)->not->toBeNull();

    Queue::assertPushed(ProcessAgentTask::class, fn (ProcessAgentTask $job): bool => $job->task->is($task));
});

test('topic posts without assignments do not create notifications or agent tasks', function () {
    Notification::fake();

    Post::factory()->for(Thread::factory()->forTopic($this->topic))->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'status' => PostStatus::Published,
    ]);

    Notification::assertNothingSent();

    expect(AgentTask::query()->count())->toBe(0);
});

test('publishing a draft with an agent mention creates agent work once', function () {
    Notification::fake();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);

    $post = Post::factory()->for(Thread::factory()->forTopic($this->topic))->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => '@researcher Draft context.',
        'status' => PostStatus::Draft,
    ]);

    expect($post->agentTasks()->count())->toBe(0);

    $post->update(['status' => PostStatus::Published]);
    $post->update(['body' => '@researcher Already sent']);

    $task = AgentTask::query()
        ->whereBelongsTo($agent)
        ->whereBelongsTo($post)
        ->sole();

    expect($task->available_at)->not->toBeNull()
        ->and($task->statusPost)->not->toBeNull()
        ->and($task->statusPost->body)->toBe('Researcher queued.')
        ->and(AgentTask::query()
            ->whereBelongsTo($agent)
            ->whereBelongsTo($post)
            ->count())->toBe(1);
});

test('removing an agent mention removes the task status reply', function () {
    Notification::fake();

    Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);

    $post = Post::factory()->for(Thread::factory()->forTopic($this->topic))->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => '@researcher Draft context.',
        'status' => PostStatus::Published,
    ]);

    $task = AgentTask::query()->whereBelongsTo($post)->sole();
    $statusPost = $task->statusPost;

    $post->update(['body' => 'No agent mention now.']);

    expect(AgentTask::query()->whereBelongsTo($post)->exists())->toBeFalse()
        ->and(Post::query()->find($statusPost->id))->toBeNull()
        ->and(Post::withTrashed()->find($statusPost->id)?->trashed())->toBeTrue();
});

test('mentioning multiple agents creates one task for each agent', function () {
    Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    Agent::factory()->for($this->workspace)->create(['name' => 'Reviewer']);

    $post = Post::factory()->for(Thread::factory()->forTopic($this->topic))->create([
        'body' => '@researcher @reviewer Please collaborate.',
        'sender_principal_id' => $this->senderPrincipal->id,
        'status' => PostStatus::Published,
    ]);

    expect($post->agentTasks)->toHaveCount(2)
        ->and($post->agentTasks->pluck('event_type')->unique()->values()->all())
        ->toBe([AgentTask::EventPostMentioned]);
});

test('mentions only create work for agents in the post workspace', function () {
    $mentionedAgent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    Agent::factory()->create(['name' => 'Reviewer']);

    $post = Post::factory()->for(Thread::factory()->forTopic($this->topic))->create([
        'body' => '@researcher @reviewer Please review.',
        'sender_principal_id' => $this->senderPrincipal->id,
        'status' => PostStatus::Published,
    ]);

    expect($post->agentTasks)->toHaveCount(1)
        ->and($post->agentTasks->first()->agent_id)->toBe($mentionedAgent->id);
});

test('an unmentioned thread reply asks the router which participating agent should respond', function () {
    Ai::fakeAgent(ExplicateAgentRouter::class, [[
        'responses' => [
            ['agent_slug' => 'researcher', 'reason' => 'The user answered the agent question.'],
        ],
    ]])->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    $thread = Thread::factory()->forTopic($this->topic)->create([
        'title' => 'Brief review',
    ]);
    $parentPost = Post::factory()->for($thread)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => 'Please inspect the brief.',
        'status' => PostStatus::Published,
    ]);
    Post::factory()->for($thread)->create([
        'sender_principal_id' => $this->workspace->principalForAgent($agent)->id,
        'body' => 'Should I include acceptance criteria?',
        'status' => PostStatus::Published,
    ]);

    $reply = Post::factory()->for($thread)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => 'Yes, add acceptance criteria and edge cases.',
        'status' => PostStatus::Published,
    ]);

    Queue::assertPushed(RouteThreadAgentReplies::class, fn (RouteThreadAgentReplies $job): bool => $job->post->is($reply));

    app(SyncAgentChatReplies::class)->route($reply);

    $task = AgentTask::query()
        ->whereBelongsTo($agent)
        ->whereBelongsTo($reply)
        ->sole();

    expect($task->event_type)->toBe(AgentTask::EventThreadRouted)
        ->and($task->status)->toBe(AgentTaskStatus::Pending)
        ->and($task->statusPost)->not->toBeNull()
        ->and($task->statusPost->thread_id)->toBe($thread->id);

    Queue::assertPushed(ProcessAgentTask::class, fn (ProcessAgentTask $job): bool => $job->task->is($task));

    Ai::assertAgentWasPrompted(ExplicateAgentRouter::class, function (AgentPrompt $prompt): bool {
        $messages = collect($prompt->agent->messages());

        return str_contains($prompt->agent->instructions(), 'Return no agents for acknowledgements')
            && str_contains($prompt->prompt, 'Yes, add acceptance criteria and edge cases.')
            && str_contains($prompt->prompt, 'Researcher (@researcher)')
            && $messages->contains(fn ($message): bool => $message->content === 'Researcher (@researcher): Should I include acceptance criteria?');
    });
});

test('the thread router uses the configured team provider key', function () {
    config(['ai.providers.openai.key' => '']);

    ProviderKey::create([
        'team_id' => $this->user->currentTeam->id,
        'provider' => Provider::OpenAI,
        'api_key' => 'sk-team-router-openai-key',
    ]);

    Ai::fakeAgent(ExplicateAgentRouter::class, function (string $prompt, $attachments, $provider): array {
        expect($provider->name())->toBe('openai')
            ->and($provider->providerCredentials()['key'])->toBe('sk-team-router-openai-key');

        return [
            'responses' => [
                ['agent_slug' => 'researcher', 'reason' => 'The user answered the agent question.'],
            ],
        ];
    })->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    AgentVersion::factory()->for($agent)->create([
        'provider' => Provider::OpenAI,
        'model' => 'gpt-4o-mini',
    ]);

    $thread = Thread::factory()->forTopic($this->topic)->create();
    Post::factory()->for($thread)->create([
        'sender_principal_id' => $this->workspace->principalForAgent($agent)->id,
        'body' => 'Should I include acceptance criteria?',
        'status' => PostStatus::Published,
    ]);

    $reply = Post::factory()->for($thread)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => 'Yes, add them.',
        'status' => PostStatus::Published,
    ]);

    app(SyncAgentChatReplies::class)->route($reply);
});

test('the thread router may decide no participating agent should respond', function () {
    Ai::fakeAgent(ExplicateAgentRouter::class, [[
        'responses' => [],
    ]])->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    $thread = Thread::factory()->forTopic($this->topic)->create();
    Post::factory()->for(Thread::factory()->forTopic($this->topic))->for($thread)->create([
        'sender_principal_id' => $this->workspace->principalForAgent($agent)->id,
        'body' => 'I updated the brief.',
        'status' => PostStatus::Published,
    ]);

    $reply = Post::factory()->for(Thread::factory()->forTopic($this->topic))->for($thread)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => 'Thanks, looks good.',
        'status' => PostStatus::Published,
    ]);

    Queue::assertPushed(RouteThreadAgentReplies::class, fn (RouteThreadAgentReplies $job): bool => $job->post->is($reply));

    app(SyncAgentChatReplies::class)->route($reply);

    expect(AgentTask::query()->whereBelongsTo($reply)->exists())->toBeFalse();

    Ai::assertAgentWasPrompted(ExplicateAgentRouter::class, fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Thanks, looks good.'));
    Queue::assertNotPushed(ProcessAgentTask::class);
});

test('an explicit agent mention in a thread bypasses the router', function () {
    Ai::fakeAgent(ExplicateAgentRouter::class)->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Researcher']);
    $thread = Thread::factory()->forTopic($this->topic)->create();
    Post::factory()->for(Thread::factory()->forTopic($this->topic))->for($thread)->create([
        'sender_principal_id' => $this->workspace->principalForAgent($agent)->id,
        'body' => 'I can revise this if needed.',
        'status' => PostStatus::Published,
    ]);

    $reply = Post::factory()->for(Thread::factory()->forTopic($this->topic))->for($thread)->create([
        'sender_principal_id' => $this->senderPrincipal->id,
        'body' => '@researcher Please revise this.',
        'status' => PostStatus::Published,
    ]);

    expect(AgentTask::query()->whereBelongsTo($reply)->sole()->event_type)->toBe(AgentTask::EventChatSummoned);

    Queue::assertNotPushed(RouteThreadAgentReplies::class);
    Ai::assertAgentNeverPrompted(ExplicateAgentRouter::class);
});

test('agent authored replies do not summon themselves when their body contains their mention', function () {
    Ai::fakeAgent(ExplicateAgentRouter::class)->preventStrayPrompts();

    $agent = Agent::factory()->for($this->workspace)->create(['name' => 'Specification Writer']);
    $thread = Thread::factory()->forTopic($this->topic)->create();

    $reply = Post::factory()->for(Thread::factory()->forTopic($this->topic))->for($thread)->create([
        'sender_principal_id' => $this->workspace->principalForAgent($agent)->id,
        'body' => 'Specification Writer (@specification-writer): Acknowledged.',
        'status' => PostStatus::Published,
    ]);

    expect(AgentTask::query()->whereBelongsTo($reply)->exists())->toBeFalse();

    Queue::assertNotPushed(ProcessAgentTask::class);
    Queue::assertNotPushed(RouteThreadAgentReplies::class);
    Ai::assertAgentNeverPrompted(ExplicateAgentRouter::class);
});

test('agent authored replies can summon other mentioned agents', function () {
    Ai::fakeAgent(ExplicateAgentRouter::class)->preventStrayPrompts();

    $writer = Agent::factory()->for($this->workspace)->create(['name' => 'Specification Writer']);
    $reviewer = Agent::factory()->for($this->workspace)->create(['name' => 'Reviewer']);
    $thread = Thread::factory()->forTopic($this->topic)->create();

    $reply = Post::factory()->for(Thread::factory()->forTopic($this->topic))->for($thread)->create([
        'sender_principal_id' => $this->workspace->principalForAgent($writer)->id,
        'body' => '@reviewer Please check this specification before I continue.',
        'status' => PostStatus::Published,
    ]);

    $task = AgentTask::query()
        ->whereBelongsTo($reviewer)
        ->whereBelongsTo($reply)
        ->sole();

    expect($task->event_type)->toBe(AgentTask::EventChatSummoned);

    Queue::assertPushed(ProcessAgentTask::class, fn (ProcessAgentTask $job): bool => $job->task->is($task));
    Queue::assertNotPushed(RouteThreadAgentReplies::class);
    Ai::assertAgentNeverPrompted(ExplicateAgentRouter::class);
});
