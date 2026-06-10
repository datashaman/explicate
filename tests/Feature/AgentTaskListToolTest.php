<?php

use App\Ai\Tools\ManageAgentTaskListTool;
use App\Enums\PostStatus;
use App\Enums\Provider;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\AgentVersion;
use App\Models\Post;
use App\Models\Thread;
use App\Models\ThreadAgentState;
use App\Models\Topic;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request as AiToolRequest;

beforeEach(function () {
    Queue::fake();
});

test('the agent task list tool persists across thread turns', function () {
    [$user, $workspace] = userWithWorkspace();
    $topic = Topic::factory()->for($workspace)->create();
    $senderPrincipal = $workspace->principalForUser($user);

    $agent = Agent::factory()->for($workspace)->create([
        'name' => 'Planner',
        'slug' => 'planner',
    ]);
    AgentVersion::factory()->for($agent)->create([
        'provider' => Provider::Gemini,
        'model' => 'gemini-2.5-flash',
    ]);

    $firstPost = Post::factory()->for(Thread::factory()->forTopic($topic))->create([
        'sender_principal_id' => $senderPrincipal->id,
        'body' => '@planner Map the work.',
        'status' => PostStatus::Published,
    ]);

    $firstTask = AgentTask::query()->whereBelongsTo($firstPost)->sole();
    $tool = new ManageAgentTaskListTool($firstTask);

    $initial = json_decode((string) $tool->handle(new AiToolRequest([
        'action' => 'list',
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($initial['items'])->toBeEmpty()
        ->and($initial['counts']['total'])->toBe(0);

    $added = json_decode((string) $tool->handle(new AiToolRequest([
        'action' => 'add',
        'text' => 'Draft the spec.',
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($added['action'])->toBe('added')
        ->and($added['items'])->toHaveCount(1)
        ->and($added['items'][0]['text'])->toBe('Draft the spec.')
        ->and($added['items'][0]['completed'])->toBeFalse();

    $replyThread = $firstPost->fresh()->thread;

    $secondPost = Post::factory()->for($replyThread)->create([
        'sender_principal_id' => $senderPrincipal->id,
        'body' => '@planner Check the spec against the brief.',
        'status' => PostStatus::Published,
    ]);

    $secondTask = AgentTask::query()->whereBelongsTo($secondPost)->sole();
    $secondTool = new ManageAgentTaskListTool($secondTask);

    $persisted = json_decode((string) $secondTool->handle(new AiToolRequest([
        'action' => 'list',
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($persisted['items'])->toHaveCount(1)
        ->and($persisted['items'][0]['text'])->toBe('Draft the spec.')
        ->and(ThreadAgentState::query()->where('thread_id', $replyThread->id)->where('agent_id', $agent->id)->exists())->toBeTrue();
});

test('the agent task list tool can add, check, uncheck, move, update, and remove items', function () {
    [$user, $workspace] = userWithWorkspace();
    $topic = Topic::factory()->for($workspace)->create();
    $senderPrincipal = $workspace->principalForUser($user);

    $agent = Agent::factory()->for($workspace)->create([
        'name' => 'Planner',
        'slug' => 'planner',
    ]);
    AgentVersion::factory()->for($agent)->create([
        'provider' => Provider::Gemini,
        'model' => 'gemini-2.5-flash',
    ]);

    $post = Post::factory()->for(Thread::factory()->forTopic($topic))->create([
        'sender_principal_id' => $senderPrincipal->id,
        'body' => '@planner Track these steps.',
        'status' => PostStatus::Published,
    ]);

    $task = AgentTask::query()->whereBelongsTo($post)->sole();
    $tool = new ManageAgentTaskListTool($task);

    $first = json_decode((string) $tool->handle(new AiToolRequest([
        'action' => 'add',
        'text' => 'First step.',
    ])), true, flags: JSON_THROW_ON_ERROR);
    $second = json_decode((string) $tool->handle(new AiToolRequest([
        'action' => 'add',
        'text' => 'Second step.',
    ])), true, flags: JSON_THROW_ON_ERROR);

    $firstId = $first['items'][0]['id'];
    $secondId = $second['items'][1]['id'];

    $checked = json_decode((string) $tool->handle(new AiToolRequest([
        'action' => 'check',
        'item_id' => $firstId,
    ])), true, flags: JSON_THROW_ON_ERROR);

    $moved = json_decode((string) $tool->handle(new AiToolRequest([
        'action' => 'move',
        'item_id' => $secondId,
        'position' => 1,
    ])), true, flags: JSON_THROW_ON_ERROR);

    $updated = json_decode((string) $tool->handle(new AiToolRequest([
        'action' => 'update',
        'item_id' => $secondId,
        'text' => 'Updated second step.',
    ])), true, flags: JSON_THROW_ON_ERROR);

    $unchecked = json_decode((string) $tool->handle(new AiToolRequest([
        'action' => 'uncheck',
        'item_id' => $firstId,
    ])), true, flags: JSON_THROW_ON_ERROR);

    $removed = json_decode((string) $tool->handle(new AiToolRequest([
        'action' => 'remove',
        'item_id' => $firstId,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($checked['items'][0]['completed'])->toBeTrue()
        ->and($moved['items'][0]['id'])->toBe($secondId)
        ->and($updated['items'][0]['text'])->toBe('Updated second step.')
        ->and($unchecked['items'][1]['completed'])->toBeFalse()
        ->and($removed['items'])->toHaveCount(1)
        ->and($removed['items'][0]['id'])->toBe($secondId);
});
