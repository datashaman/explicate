<?php

use App\Models\User;
use Livewire\Livewire;

function userNeedingOnboarding(): User
{
    $user = User::factory()->create(['current_workspace_id' => null]);
    $user->currentTeam; // ensure team loaded

    return $user;
}

test('new user is redirected to onboarding when hitting dashboard', function () {
    $user = userNeedingOnboarding();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('onboarding'));
});

test('onboarding page loads for user without workspace', function () {
    $user = userNeedingOnboarding();

    $this->actingAs($user)
        ->get(route('onboarding'))
        ->assertOk();
});

test('already onboarded user visiting onboarding is redirected to dashboard', function () {
    [$user] = userWithWorkspace();

    Livewire::actingAs($user)
        ->test('pages::onboarding')
        ->assertRedirect(route('dashboard'));
});

test('skipping wizard creates workspace topic and agent with defaults', function () {
    $user = userNeedingOnboarding();

    Livewire::actingAs($user)
        ->test('pages::onboarding')
        ->call('skip')
        ->assertRedirect(route('dashboard'));

    $user->refresh();

    expect($user->currentWorkspace)->not->toBeNull()
        ->and($user->currentWorkspace->name)->toBe('My Workspace')
        ->and($user->currentWorkspace->topics()->count())->toBe(1)
        ->and($user->currentWorkspace->agents()->count())->toBe(1);
});

test('completing wizard creates workspace topic and agent with custom values', function () {
    $user = userNeedingOnboarding();

    Livewire::actingAs($user)
        ->test('pages::onboarding')
        ->set('workspaceName', 'Acme HQ')
        ->call('next')
        ->set('topicName', 'Engineering')
        ->call('next')
        ->set('agentName', 'Scribe')
        ->set('agentPrompt', 'Write clearly.')
        ->call('complete')
        ->assertRedirect(route('dashboard'));

    $user->refresh();
    $workspace = $user->currentWorkspace;

    expect($workspace->name)->toBe('Acme HQ')
        ->and($workspace->topics()->where('name', 'Engineering')->exists())->toBeTrue()
        ->and($workspace->agents()->where('name', 'Scribe')->exists())->toBeTrue();
});

test('next validates workspace name before advancing', function () {
    $user = userNeedingOnboarding();

    Livewire::actingAs($user)
        ->test('pages::onboarding')
        ->set('workspaceName', '')
        ->call('next')
        ->assertHasErrors(['workspaceName'])
        ->assertSet('step', 1);
});
