<?php

use App\Models\User;
use Livewire\Livewire;

afterEach(function () {
    $workspacesDir = storage_path('app/workspaces');
    if (is_dir($workspacesDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($workspacesDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getRealPath()) : unlink($entry->getRealPath());
        }
        rmdir($workspacesDir);
    }
});

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
    $workspace->filesystem()->mkdir('docs');

    Livewire::actingAs($user)
        ->test('pages::dashboard', ['action' => 'files'])
        ->assertSee('Files')
        ->set('selectedWorkspaceFilePath', 'docs')
        ->set('newWorkspaceFileType', 'file')
        ->set('newWorkspaceFileName', 'spec.md')
        ->call('createWorkspaceFile')
        ->assertSet('workspaceFileContent', '')
        ->set('workspaceFileContent', "# Specification\n\nContent")
        ->call('saveSelectedWorkspaceFile')
        ->assertHasNoErrors();

    expect($workspace->filesystem()->exists('docs/spec.md'))->toBeTrue();
    expect($workspace->filesystem()->read('docs/spec.md'))->toBe("# Specification\n\nContent");
});

test('workspace file count only includes files and not folders', function () {
    [$user, $workspace] = userWithWorkspace();
    $workspace->filesystem()->mkdir('docs');
    $workspace->filesystem()->write('spec.md', '');

    $this
        ->actingAs($user)
        ->get(route('dashboard', ['action' => 'files']))
        ->assertOk()
        ->assertSee('data-test="workspace-files-count"', false)
        ->assertSeeText('1');
});
