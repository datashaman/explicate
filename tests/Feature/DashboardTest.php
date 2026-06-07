<?php

use App\Enums\WorkspaceFileType;
use App\Models\User;
use App\Models\WorkspaceFile;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});

test('workspace layout renders the workspace switcher', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response
        ->assertOk()
        ->assertSee('data-test="workspace-switcher-trigger"', false);
});

test('workspace layout renders the user settings menu', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response
        ->assertOk()
        ->assertSee('data-test="sidebar-menu-button"', false)
        ->assertSee(route('profile.edit'), false)
        ->assertSee('Settings');
});

test('users can manage workspace files from the dashboard', function () {
    [$user, $workspace] = userWithWorkspace();
    $folder = WorkspaceFile::factory()->for($workspace)->folder()->create([
        'name' => 'docs',
    ]);

    Livewire::actingAs($user)
        ->test('pages::dashboard', ['action' => 'files'])
        ->assertSee('Files')
        ->set('selectedWorkspaceFileId', $folder->id)
        ->set('newWorkspaceFileType', WorkspaceFileType::File->value)
        ->set('newWorkspaceFileName', 'spec.md')
        ->call('createWorkspaceFile')
        ->assertSet('workspaceFileContent', '')
        ->set('workspaceFileContent', "# Specification\n\nContent")
        ->call('saveSelectedWorkspaceFile')
        ->assertHasNoErrors();

    $file = $workspace->files()->where('path', 'docs/spec.md')->first();

    expect($file)->not->toBeNull();
    expect($file?->type)->toBe(WorkspaceFileType::File);
    expect($file?->content)->toBe("# Specification\n\nContent");
});
