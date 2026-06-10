<?php

use App\Enums\Provider;
use App\Models\ProviderKey;
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

test('skipping wizard creates workspace and agent with defaults', function () {
    $user = userNeedingOnboarding();

    Livewire::actingAs($user)
        ->test('pages::onboarding')
        ->call('skip')
        ->assertRedirect(route('dashboard'));

    $user->refresh();

    expect($user->currentWorkspace)->not->toBeNull()
        ->and($user->currentWorkspace->name)->toBe('My Workspace')
        ->and($user->currentWorkspace->topics()->count())->toBe(0)
        ->and($user->currentWorkspace->agents()->count())->toBe(1);
});

test('completing wizard saves api key and creates workspace and agent', function () {
    $user = userNeedingOnboarding();

    Livewire::actingAs($user)
        ->test('pages::onboarding')
        ->set('workspaceName', 'Acme HQ')
        ->call('next')
        ->set('keyProvider', Provider::Anthropic->value)
        ->set('keyValue', 'sk-ant-test-key-1234567890')
        ->call('next')
        ->set('agentName', 'Scribe')
        ->set('agentPrompt', 'Write clearly.')
        ->call('complete')
        ->assertRedirect(route('dashboard'));

    $user->refresh();
    $workspace = $user->currentWorkspace;

    expect($workspace->name)->toBe('Acme HQ')
        ->and($workspace->topics()->exists())->toBeFalse()
        ->and($workspace->agents()->where('name', 'Scribe')->exists())->toBeTrue();

    expect(
        ProviderKey::where('team_id', $user->currentTeam->id)
            ->where('provider', Provider::Anthropic)
            ->whereNull('workspace_id')
            ->exists()
    )->toBeTrue();
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

test('next validates api key before advancing from step 2', function () {
    $user = userNeedingOnboarding();

    Livewire::actingAs($user)
        ->test('pages::onboarding')
        ->set('workspaceName', 'Acme HQ')
        ->call('next')
        ->set('keyValue', '')
        ->call('next')
        ->assertHasErrors(['keyValue'])
        ->assertSet('step', 2);
});

test('agent step only shows providers with configured keys', function () {
    $user = userNeedingOnboarding();

    ProviderKey::create([
        'team_id' => $user->currentTeam->id,
        'workspace_id' => null,
        'provider' => Provider::Anthropic,
        'api_key' => 'sk-ant-test-key-1234567890',
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::onboarding')
        ->set('workspaceName', 'Acme HQ')
        ->call('next')
        ->set('keyProvider', Provider::OpenAI->value)
        ->set('keyValue', 'sk-openai-test-key-1234567890')
        ->call('next');

    $available = collect($component->get('availableProviders'))
        ->pluck('value')
        ->all();

    expect($available)->toContain(Provider::Anthropic->value)
        ->and($available)->toContain(Provider::OpenAI->value)
        ->and($available)->not->toContain(Provider::Gemini->value);
});
