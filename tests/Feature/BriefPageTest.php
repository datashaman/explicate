<?php

use App\Enums\BriefCategory;
use App\Models\Brief;
use App\Models\Thread;
use Livewire\Livewire;

test('briefs page lists current workspace briefs', function () {
    [$user, $workspace] = userWithWorkspace();
    $brief = Brief::factory()->for($workspace)->create(['summary' => 'Improve onboarding']);
    Brief::factory()->create(['summary' => 'Other workspace brief']);

    $this->actingAs($user)
        ->get(route('briefs'))
        ->assertOk()
        ->assertSee('data-test="briefs-page"', false)
        ->assertSee('Improve onboarding')
        ->assertDontSee('Other workspace brief')
        ->assertSee('data-test="brief-row-'.$brief->id.'"', false);
});

test('briefs page creates a brief', function () {
    [$user, $workspace] = userWithWorkspace();
    $thread = Thread::factory()->for($workspace)->create(['title' => 'Planning chat']);

    Livewire::actingAs($user)
        ->test('pages::briefs')
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
        ->test('pages::briefs')
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
        ->test('pages::briefs')
        ->call('selectBrief', $brief->id)
        ->call('deleteBrief');

    expect(Brief::find($brief->id))->toBeNull()
        ->and(Brief::withTrashed()->find($brief->id))->not->toBeNull();
});
