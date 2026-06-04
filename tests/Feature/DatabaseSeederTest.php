<?php

use App\Models\User;
use App\Models\Workspace;

test('database seeder creates demo workspace content', function () {
    $this->seed();

    $user = User::where('email', 'test@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->currentWorkspace)->toBeInstanceOf(Workspace::class);
    expect($user->currentWorkspace->name)->toBe('My First Workspace');
    expect($user->currentWorkspace->topics)->toHaveCount(4);
    expect($user->currentWorkspace->agents)->toHaveCount(4);

    $designTopic = $user->currentWorkspace->topics()->where('name', 'Design')->first();
    $engineeringTopic = $user->currentWorkspace->topics()->where('name', 'Engineering')->first();

    expect($designTopic)->not->toBeNull();
    expect($designTopic->messages()->count())->toBeGreaterThanOrEqual(2);
    expect($designTopic->agents()->count())->toBeGreaterThanOrEqual(2);

    expect($engineeringTopic)->not->toBeNull();
    expect($engineeringTopic->messages()->count())->toBeGreaterThanOrEqual(2);

    $writerAgent = $user->currentWorkspace->agents()->where('name', 'Writer')->first();

    expect($writerAgent)->not->toBeNull();
    expect($writerAgent->versions)->toHaveCount(1);
    expect($writerAgent->latestVersion)->not->toBeNull();
});
