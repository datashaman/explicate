<?php

use App\Enums\BriefCategory;
use App\Enums\TaskStatus;
use App\Models\Brief;
use App\Models\Plan;
use App\Models\Task;
use App\Models\Thread;
use Livewire\Livewire;

test('briefs page lists current workspace briefs', function () {
    [$user, $workspace] = userWithWorkspace();
    $brief = Brief::factory()->for($workspace)->create(['summary' => 'Improve onboarding']);
    Brief::factory()->create(['summary' => 'Other workspace brief']);

    $this->actingAs($user)
        ->get(route('briefs'))
        ->assertRedirect(route('briefs.show', $brief));

    $this->actingAs($user)
        ->get(route('briefs.show', $brief))
        ->assertOk()
        ->assertSee('data-test="brief-show-page"', false)
        ->assertSee('data-test="brief-view"', false)
        ->assertSee('data-test="plans-nav-link"', false)
        ->assertSee('data-test="edit-brief-button"', false)
        ->assertSee('Improve onboarding')
        ->assertDontSee('Other workspace brief')
        ->assertSee('data-test="brief-row-'.$brief->id.'"', false)
        ->assertSee(route('briefs.show', $brief), false);
});

test('briefs page shows plan state for each brief', function () {
    [$user, $workspace] = userWithWorkspace();
    Brief::factory()->for($workspace)->create([
        'summary' => 'Unplanned brief',
        'updated_at' => now()->subMinute(),
    ]);

    $plannedBrief = Brief::factory()->for($workspace)->create([
        'summary' => 'Planned brief',
        'updated_at' => now(),
    ]);
    $plan = Plan::factory()->for($plannedBrief)->create();
    Task::factory()->for($plan)->create(['position' => 1, 'status' => TaskStatus::Done]);
    Task::factory()->for($plan)->create(['position' => 2]);

    $this->actingAs($user)
        ->get(route('briefs.show', $plannedBrief))
        ->assertOk()
        ->assertSee('Planned brief')
        ->assertSee('1/2 done')
        ->assertSee('data-test="brief-plan-card-open"', false)
        ->assertSee('data-test="brief-plan-card-link"', false)
        ->assertSee(route('briefs.plan', $plannedBrief), false)
        ->assertSee('Unplanned brief')
        ->assertSee('No plan');
});

test('briefs page renders an empty state when there are no briefs', function () {
    [$user] = userWithWorkspace();

    $this->actingAs($user)
        ->get(route('briefs'))
        ->assertOk()
        ->assertSee('data-test="briefs-page"', false)
        ->assertSee('No briefs yet.');
});

test('brief show page renders a direct linkable brief view', function () {
    [$user, $workspace] = userWithWorkspace();
    $brief = Brief::factory()->for($workspace)->create([
        'summary' => 'Direct brief',
        'current_behaviour' => 'The current state is hard to inspect.',
        'expected_behaviour' => 'The brief has its own shareable view.',
    ]);

    $this->actingAs($user)
        ->get(route('briefs.show', $brief))
        ->assertOk()
        ->assertSee('data-test="brief-show-page"', false)
        ->assertSee('data-test="brief-view"', false)
        ->assertSee('data-test="brief-row-'.$brief->id.'"', false)
        ->assertSee('data-test="brief-detail"', false)
        ->assertSee('Direct brief')
        ->assertSee('The brief has its own shareable view.')
        ->assertSee(route('briefs.show', $brief), false)
        ->assertSee('data-test="edit-brief-button"', false)
        ->assertSee(route('briefs.edit', $brief), false);
});

test('brief show page rejects briefs outside the current workspace', function () {
    [$user] = userWithWorkspace();
    $brief = Brief::factory()->create();

    $this->actingAs($user)
        ->get(route('briefs.show', $brief))
        ->assertNotFound();
});

test('briefs page creates a brief', function () {
    [$user, $workspace] = userWithWorkspace();
    $thread = Thread::factory()->for($workspace)->create(['title' => 'Planning chat']);

    Livewire::actingAs($user)
        ->test('pages::briefs-edit')
        ->call('startNewBrief')
        ->set('category', BriefCategory::Bug->value)
        ->set('summary', 'Login redirects unexpectedly')
        ->set('currentBehaviour', 'Users are redirected back to login.')
        ->set('expectedBehaviour', 'Users land on the dashboard.')
        ->set('outOfScope', 'Changing passkeys.')
        ->set('sourceThreadId', $thread->id)
        ->set('newAcceptanceCriterion', 'A feature test covers the redirect.')
        ->call('addAcceptanceCriterion')
        ->call('saveBrief')
        ->assertHasNoErrors();

    $brief = $workspace->briefs()->sole();

    expect($brief->category)->toBe(BriefCategory::Bug)
        ->and($brief->source_thread_id)->toBe($thread->id)
        ->and($brief->summary)->toBe('Login redirects unexpectedly')
        ->and($brief->acceptance_criteria)->toBe([
            ['text' => 'A feature test covers the redirect.', 'done' => false],
        ]);
});

test('briefs page updates and deletes a brief', function () {
    [$user, $workspace] = userWithWorkspace();
    $brief = Brief::factory()->for($workspace)->create([
        'summary' => 'Original summary',
        'acceptance_criteria' => [
            ['text' => 'Original criterion.', 'done' => false],
        ],
    ]);

    Livewire::actingAs($user)
        ->test('pages::briefs-edit')
        ->call('selectBrief', $brief->id)
        ->set('summary', 'Updated summary')
        ->call('toggleAcceptanceCriterion', 0)
        ->call('saveBrief')
        ->assertHasNoErrors();

    expect($brief->fresh()->summary)->toBe('Updated summary')
        ->and($brief->fresh()->acceptance_criteria)->toBe([
            ['text' => 'Original criterion.', 'done' => true],
        ]);

    Livewire::actingAs($user)
        ->test('pages::briefs-edit')
        ->call('selectBrief', $brief->id)
        ->call('deleteBrief');

    expect(Brief::find($brief->id))->toBeNull()
        ->and(Brief::withTrashed()->find($brief->id))->not->toBeNull();
});

test('brief edit page renders the current editor UI', function () {
    [$user, $workspace] = userWithWorkspace();
    $brief = Brief::factory()->for($workspace)->create(['summary' => 'Editable brief']);

    $this->actingAs($user)
        ->get(route('briefs.edit', $brief))
        ->assertOk()
        ->assertSee('data-test="brief-form"', false)
        ->assertSee('Editable brief')
        ->assertSee('data-test="view-brief-button"', false)
        ->assertSee(route('briefs.show', $brief), false);
});
