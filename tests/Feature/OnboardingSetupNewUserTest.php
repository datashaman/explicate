<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\Agent;
use App\Models\Topic;
use App\Models\Workspace;

it('creates workspace, topic, and analyst agent on registration', function () {
    $action = app(CreateNewUser::class);

    $user = $action->create([
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $workspace = Workspace::where('team_id', $user->currentTeam->id)->first();

    expect($workspace)->not->toBeNull()
        ->and($workspace->name)->toBe('My Workspace')
        ->and($user->fresh()->current_workspace_id)->toBe($workspace->id);

    expect(Topic::where('workspace_id', $workspace->id)->count())->toBe(1);
    expect(Topic::where('workspace_id', $workspace->id)->first()->name)->toBe('General');

    $agent = Agent::where('workspace_id', $workspace->id)->first();

    expect($agent)->not->toBeNull()
        ->and($agent->name)->toBe('Analyst')
        ->and($agent->latestVersion->provider->value)->toBe('anthropic')
        ->and($agent->latestVersion->model)->toBe('claude-sonnet-4-6');
});
