<?php

use App\Enums\BriefCategory;
use App\Models\Brief;
use App\Models\Thread;
use App\Models\Workspace;

test('a workspace can have many briefs', function () {
    $workspace = Workspace::factory()->create();

    Brief::factory()->count(2)->for($workspace)->create();

    expect($workspace->briefs)->toHaveCount(2);
});

test('a brief belongs to a workspace', function () {
    $brief = Brief::factory()->create();

    expect($brief->workspace)->toBeInstanceOf(Workspace::class);
});

test('a brief can reference its source thread', function () {
    $thread = Thread::factory()->create();
    $brief = Brief::factory()->forThread($thread)->create();

    expect($brief->thread)->toBeInstanceOf(Thread::class)
        ->and($brief->thread->is($thread))->toBeTrue()
        ->and($thread->briefs()->first()->is($brief))->toBeTrue()
        ->and($brief->workspace->is($thread->workspace))->toBeTrue();
});

test('a brief can exist without a source thread', function () {
    $brief = Brief::factory()->create();

    expect($brief->thread)->toBeNull();
});

test('a brief stores category and list fields', function () {
    $brief = Brief::factory()->create([
        'category' => BriefCategory::Bug,
        'summary' => 'Login fails after password reset',
        'current_behaviour' => 'Users are redirected back to login without an error.',
        'expected_behaviour' => 'Users should be signed in after resetting their password.',
        'key_interfaces' => ['login form', 'password reset flow'],
        'acceptance_criteria' => [
            ['text' => 'Reset password signs the user in.', 'done' => false],
            ['text' => 'A regression test covers the flow.', 'done' => true],
        ],
        'out_of_scope' => 'Changing two-factor authentication.',
    ])->refresh();

    expect($brief->category)->toBe(BriefCategory::Bug)
        ->and($brief->summary)->toBe('Login fails after password reset')
        ->and($brief->key_interfaces)->toBe(['login form', 'password reset flow'])
        ->and($brief->acceptance_criteria)->toBe([
            ['text' => 'Reset password signs the user in.', 'done' => false],
            ['text' => 'A regression test covers the flow.', 'done' => true],
        ]);
});

test('brief list fields default to empty arrays', function () {
    $brief = Brief::create([
        'workspace_id' => Workspace::factory()->create()->id,
        'category' => BriefCategory::Feature,
        'summary' => 'Add repository picker',
        'current_behaviour' => 'Repositories are selected outside the brief.',
        'expected_behaviour' => 'Briefs can reference relevant repositories.',
    ])->refresh();

    expect($brief->key_interfaces)->toBe([])
        ->and($brief->acceptance_criteria)->toBe([]);
});
