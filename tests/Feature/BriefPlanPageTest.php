<?php

use App\Enums\TaskStatus;
use App\Models\Brief;
use App\Models\Plan;
use App\Models\Task;
use Livewire\Livewire;

test('brief plan page renders brief context', function () {
    [$user, $workspace] = userWithWorkspace();
    $brief = Brief::factory()->for($workspace)->create([
        'summary' => 'Improve onboarding',
        'expected_behaviour' => 'Users can finish setup without help.',
    ]);

    $this->actingAs($user)
        ->get(route('briefs.plan', $brief))
        ->assertOk()
        ->assertSee('data-test="brief-plan-page"', false)
        ->assertSee('data-test="plans-nav-link"', false)
        ->assertSee('Improve onboarding')
        ->assertSee('Users can finish setup without help.');
});

test('plans page lists current workspace briefs with plan state', function () {
    [$user, $workspace] = userWithWorkspace();
    $plannedBrief = Brief::factory()->for($workspace)->create(['summary' => 'Improve onboarding']);
    $plan = Plan::factory()->for($plannedBrief)->create(['summary' => 'Small steps.']);
    Task::factory()->for($plan)->create(['status' => TaskStatus::Done]);

    Brief::factory()->for($workspace)->create(['summary' => 'Write docs']);
    Brief::factory()->create(['summary' => 'Other workspace brief']);

    $this->actingAs($user)
        ->get(route('plans'))
        ->assertOk()
        ->assertSee('data-test="plans-page"', false)
        ->assertSee('data-test="plans-nav-link"', false)
        ->assertSee('Improve onboarding')
        ->assertSee('Small steps.')
        ->assertSee('1/1 done')
        ->assertSee('Write docs')
        ->assertSee('No plan')
        ->assertDontSee('Other workspace brief');
});

test('brief plan page rejects briefs outside the current workspace', function () {
    [$user] = userWithWorkspace();
    $brief = Brief::factory()->create();

    $this->actingAs($user)
        ->get(route('briefs.plan', $brief))
        ->assertNotFound();
});

test('brief plan page creates a plan for a brief', function () {
    [$user, $workspace] = userWithWorkspace();
    $brief = Brief::factory()->for($workspace)->create();

    Livewire::actingAs($user)
        ->test('pages::brief-plan', ['brief' => $brief])
        ->set('planSummary', 'Ship this in small, reviewable steps.')
        ->set('newPlanTask', 'Create the migration.')
        ->call('addPlanTask')
        ->set('planTasks.0.expected_artifact', 'Migration updates the tasks table.')
        ->set('planTasks.0.status', TaskStatus::InProgress->value)
        ->set('newPlanTask', 'Add the editor UI.')
        ->call('addPlanTask')
        ->call('savePlan')
        ->assertHasNoErrors();

    $plan = $brief->fresh()->plan;

    expect($plan)->toBeInstanceOf(Plan::class)
        ->and($plan->summary)->toBe('Ship this in small, reviewable steps.')
        ->and($plan->tasks->pluck('text')->all())->toBe([
            'Create the migration.',
            'Add the editor UI.',
        ])
        ->and($plan->tasks->first()->expected_artifact)->toBe('Migration updates the tasks table.')
        ->and($plan->tasks->first()->status)->toBe(TaskStatus::InProgress)
        ->and($plan->tasks->last()->status)->toBe(TaskStatus::Pending);
});

test('brief plan page updates plan tasks', function () {
    [$user, $workspace] = userWithWorkspace();
    $brief = Brief::factory()->for($workspace)->create();
    $plan = Plan::factory()->for($brief)->create(['summary' => 'Original plan.']);
    $first = Task::factory()->for($plan)->create(['text' => 'First task.', 'expected_artifact' => 'First artifact.', 'position' => 1]);
    $second = Task::factory()->for($plan)->create(['text' => 'Second task.', 'position' => 2]);

    Livewire::actingAs($user)
        ->test('pages::brief-plan', ['brief' => $brief])
        ->set('planSummary', 'Updated plan.')
        ->set('planTasks.0.status', TaskStatus::Done->value)
        ->set('planTasks.1.text', 'Updated second task.')
        ->set('planTasks.1.expected_artifact', 'Updated artifact.')
        ->call('movePlanTaskDown', 0)
        ->call('savePlan')
        ->assertHasNoErrors();

    $tasks = $plan->fresh()->tasks;

    expect($plan->fresh()->summary)->toBe('Updated plan.')
        ->and($tasks->pluck('id')->all())->toBe([$second->id, $first->id])
        ->and($tasks->pluck('text')->all())->toBe(['Updated second task.', 'First task.'])
        ->and($tasks->first()->expected_artifact)->toBe('Updated artifact.')
        ->and($tasks->pluck('position')->all())->toBe([1, 2])
        ->and($tasks->last()->status)->toBe(TaskStatus::Done);
});
