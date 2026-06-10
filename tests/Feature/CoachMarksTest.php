<?php

use App\Models\User;
use Livewire\Livewire;

test('new user sees coaching marks on first dashboard visit', function () {
    [$user] = userWithWorkspace(['coach_marks_seen_at' => null]);

    $component = Livewire::actingAs($user)->test('pages::dashboard');

    expect($component->get('showCoachMarks'))->toBeTrue();
});

test('user who has seen coaching marks does not see them again', function () {
    [$user] = userWithWorkspace(['coach_marks_seen_at' => now()]);

    $component = Livewire::actingAs($user)->test('pages::dashboard');

    expect($component->get('showCoachMarks'))->toBeFalse();
});

test('dismissCoachMarks stamps seen_at and hides marks', function () {
    [$user] = userWithWorkspace(['coach_marks_seen_at' => null]);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->call('dismissCoachMarks');

    expect($user->fresh()->hasSeenCoachMarks())->toBeTrue();
});

test('finishCoachMarks selects first topic and prefills composer', function () {
    [$user, $workspace] = userWithWorkspace(['coach_marks_seen_at' => null]);
    $topic = $workspace->topics()->create(['name' => 'General']);

    $component = Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->call('finishCoachMarks', 'My suggestion here.');

    $component->assertSet('selectedTopicSlug', $topic->slug)
        ->assertSet('quickPostBody', 'My suggestion here.');

    expect($user->fresh()->hasSeenCoachMarks())->toBeTrue();
});

test('finishCoachMarks stamps seen_at even when workspace has no topics', function () {
    [$user] = userWithWorkspace(['coach_marks_seen_at' => null]);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->call('finishCoachMarks', 'A suggestion.');

    expect($user->fresh()->hasSeenCoachMarks())->toBeTrue();
});

test('hasSeenCoachMarks returns false when coach_marks_seen_at is null', function () {
    $user = User::factory()->create(['coach_marks_seen_at' => null]);

    expect($user->hasSeenCoachMarks())->toBeFalse();
});

test('hasSeenCoachMarks returns true when coach_marks_seen_at is set', function () {
    $user = User::factory()->create(['coach_marks_seen_at' => now()]);

    expect($user->hasSeenCoachMarks())->toBeTrue();
});
