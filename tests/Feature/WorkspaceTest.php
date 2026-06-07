<?php

use App\Enums\TeamRole;
use App\Models\Agent;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;

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

test('a team can have many workspaces', function () {
    $team = Team::factory()->create();
    $workspaces = Workspace::factory()->count(3)->for($team)->create();

    expect($team->workspaces)->toHaveCount(3);
    expect($team->workspaces->pluck('id')->sort()->values()->toArray())->toEqual($workspaces->pluck('id')->sort()->values()->toArray());
});

test('a team can have zero workspaces', function () {
    $team = Team::factory()->create();

    expect($team->workspaces)->toHaveCount(0);
});

test('a workspace belongs to a team', function () {
    $workspace = Workspace::factory()->create();

    expect($workspace->team)->toBeInstanceOf(Team::class);
});

test('workspaces are soft deleted', function () {
    $workspace = Workspace::factory()->create();
    $workspace->delete();

    expect(Workspace::withTrashed()->find($workspace->id))->not->toBeNull();
    expect(Workspace::find($workspace->id))->toBeNull();
});

test('slug is unique per team', function () {
    $team = Team::factory()->create();

    Workspace::factory()->for($team)->create(['name' => 'My Workspace', 'slug' => 'my-workspace']);

    expect(fn () => Workspace::factory()->for($team)->create(['name' => 'My Workspace', 'slug' => 'my-workspace']))
        ->toThrow(QueryException::class);
});

test('workspace lists team member and agent principals by label', function () {
    [$user, $workspace] = userWithWorkspace(['name' => 'Current User']);
    $member = User::factory()->create(['name' => 'Member User']);
    $user->currentTeam->memberships()->create([
        'user_id' => $member->id,
        'role' => TeamRole::Member,
    ]);
    Agent::factory()->for($workspace)->create(['name' => 'Research Agent']);

    $principals = $workspace->availablePrincipalsForTeam($user->currentTeam);

    expect($principals->map->label()->all())->toBe([
        'Current User',
        'Member User',
        'Research Agent',
    ]);
});

test('workspace filesystem deletes folder and all nested files recursively', function () {
    $workspace = Workspace::factory()->create();
    $fs = $workspace->filesystem();

    $fs->mkdir('docs');
    $fs->write('docs/spec.md', '# Spec');
    $fs->write('docs/notes.md', 'Notes');

    expect($fs->isDirectory('docs'))->toBeTrue();
    expect($fs->exists('docs/spec.md'))->toBeTrue();

    $fs->delete('docs');

    expect($fs->exists('docs'))->toBeFalse();
    expect($fs->exists('docs/spec.md'))->toBeFalse();
});
