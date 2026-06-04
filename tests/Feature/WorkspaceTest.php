<?php

use App\Models\Team;
use App\Models\Workspace;
use Illuminate\Database\QueryException;

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
