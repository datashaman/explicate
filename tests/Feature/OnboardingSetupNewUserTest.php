<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\Workspace;

it('creates a personal team and marks the user as needing onboarding', function () {
    $action = app(CreateNewUser::class);

    $user = $action->create([
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect($user->currentTeam)->not->toBeNull()
        ->and($user->needsOnboarding())->toBeTrue()
        ->and(Workspace::where('team_id', $user->currentTeam->id)->count())->toBe(0);
});
