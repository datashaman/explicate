<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('workspace switcher shows workspaces for current team', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $workspaces = Workspace::factory()->count(2)->for($team)->create();

    Livewire::actingAs($user)
        ->test('workspace-switcher')
        ->assertSee($workspaces[0]->name)
        ->assertSee($workspaces[1]->name);
});

test('workspace switcher shows no workspaces message when team has none', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('workspace-switcher')
        ->assertSee(__('No workspaces'));
});

test('user can switch workspace', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $workspace = Workspace::factory()->for($team)->create();

    Livewire::actingAs($user)
        ->test('workspace-switcher')
        ->call('switchWorkspace', $workspace->slug)
        ->assertDispatched('workspace-switched');

    expect($user->fresh()->current_workspace_id)->toBe($workspace->id);
});

test('user cannot switch to workspace from another team', function () {
    $user = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create();

    expect(fn () => Livewire::actingAs($user)
        ->test('workspace-switcher')
        ->call('switchWorkspace', $otherWorkspace->slug)
    )->toThrow(ModelNotFoundException::class);

    expect($user->fresh()->current_workspace_id)->toBeNull();
});

test('current workspace is marked in the switcher', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $workspace = Workspace::factory()->for($team)->create();
    $user->switchWorkspace($workspace);

    expect($user->isCurrentWorkspace($workspace))->toBeTrue();
    expect($user->toUserWorkspaces()->first()->isCurrent)->toBeTrue();
});

test('creating a workspace switches to it', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('create-workspace-modal')
        ->set('workspaceName', 'My Workspace')
        ->call('createWorkspace')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $workspace = Workspace::where('name', 'My Workspace')->first();

    expect($workspace)->not->toBeNull();
    expect($user->fresh()->current_workspace_id)->toBe($workspace->id);
});

test('switching teams clears current workspace', function () {
    $user = User::factory()->create();
    $firstTeam = $user->currentTeam;

    $workspace = Workspace::factory()->for($firstTeam)->create();
    $user->switchWorkspace($workspace);

    expect($user->fresh()->current_workspace_id)->toBe($workspace->id);

    $secondTeam = Team::factory()->create();
    $secondTeam->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);
    $user->switchTeam($secondTeam);

    expect($user->fresh()->current_workspace_id)->toBeNull();
});
