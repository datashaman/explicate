<?php

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

test('redirect sends user to github oauth', function () {
    Socialite::fake('github');

    $this->get(route('auth.github.redirect'))
        ->assertRedirect();
});

test('callback logs in existing user by github id', function () {
    $user = User::factory()->create(['github_id' => 'gh-42', 'github_nickname' => 'octocat']);

    Socialite::fake('github', (new SocialiteUser)->map([
        'id' => 'gh-42',
        'name' => 'Octo Cat',
        'email' => $user->email,
        'nickname' => 'octocat',
    ])->setToken('tok-abc'));

    $this->get(route('auth.github.callback'))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();
    $this->assertAuthenticatedAs($user);

    expect($user->fresh()->github_token)->toBe('tok-abc');
});

test('callback creates new user when github id not found', function () {
    Socialite::fake('github', (new SocialiteUser)->map([
        'id' => 'gh-99',
        'name' => 'New User',
        'email' => 'newuser@example.com',
        'nickname' => 'newuser',
    ])->setToken('tok-new'));

    $this->get(route('auth.github.callback'))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();

    $this->assertDatabaseHas('users', [
        'github_id' => 'gh-99',
        'github_nickname' => 'newuser',
        'email' => 'newuser@example.com',
    ]);
});

test('callback connects github to authenticated user', function () {
    $user = User::factory()->create();

    Socialite::fake('github', (new SocialiteUser)->map([
        'id' => 'gh-77',
        'name' => $user->name,
        'email' => $user->email,
        'nickname' => 'connectedcat',
    ])->setToken('tok-connect'));

    $this->actingAs($user)
        ->get(route('auth.github.callback'))
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())
        ->github_id->toBe('gh-77')
        ->github_nickname->toBe('connectedcat')
        ->github_token->toBe('tok-connect');
});

test('callback creates new user with noreply email when github email is private', function () {
    Socialite::fake('github', (new SocialiteUser)->map([
        'id' => 'gh-55',
        'name' => 'Private Email User',
        'email' => null,
        'nickname' => 'privateuser',
    ])->setToken('tok-private'));

    $this->get(route('auth.github.callback'))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();

    $this->assertDatabaseHas('users', [
        'github_id' => 'gh-55',
        'github_nickname' => 'privateuser',
        'email' => 'gh-55+privateuser@users.noreply.github.com',
    ]);
});

test('callback redirects with error on denied authorization', function () {
    Socialite::shouldReceive('driver')->with('github')->andReturnSelf();
    Socialite::shouldReceive('user')->andThrow(new Exception('Access denied'));

    $this->get(route('auth.github.callback'))
        ->assertRedirect(route('login'));
});
