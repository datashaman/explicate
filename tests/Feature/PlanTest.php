<?php

use App\Models\Brief;
use App\Models\Plan;
use App\Models\Task;

test('a brief has one plan', function () {
    $brief = Brief::factory()->create();
    $plan = Plan::factory()->for($brief)->create();

    expect($brief->plan->is($plan))->toBeTrue();
});

test('a plan belongs to a brief', function () {
    $plan = Plan::factory()->create();

    expect($plan->brief)->toBeInstanceOf(Brief::class);
});

test('a plan has many ordered tasks', function () {
    $plan = Plan::factory()->create();

    $third = Task::factory()->for($plan)->create(['text' => 'Third', 'position' => 3]);
    $first = Task::factory()->for($plan)->create(['text' => 'First', 'position' => 1]);
    $second = Task::factory()->for($plan)->create(['text' => 'Second', 'position' => 2]);

    expect($plan->tasks->pluck('id')->all())->toBe([
        $first->id,
        $second->id,
        $third->id,
    ]);
});

test('a task belongs to a plan', function () {
    $task = Task::factory()->create();

    expect($task->plan)->toBeInstanceOf(Plan::class);
});

test('tasks default to incomplete', function () {
    $task = Task::factory()->create();

    expect($task->done)->toBeFalse();
});

test('task factory can create completed tasks', function () {
    $task = Task::factory()->done()->create();

    expect($task->done)->toBeTrue();
});

test('deleting a plan deletes its tasks', function () {
    $plan = Plan::factory()->create();
    $task = Task::factory()->for($plan)->create();

    $plan->delete();

    $this->assertModelMissing($task);
});
