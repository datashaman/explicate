<?php

use App\Models\User;

test('configured users may view horizon outside local environments', function () {
    config([
        'app.env' => 'production',
        'horizon.access_emails' => ['admin@example.com'],
    ]);

    $user = User::factory()->create([
        'email' => 'Admin@Example.com',
    ]);

    $this->actingAs($user)
        ->get('/horizon')
        ->assertOk();
});

test('unconfigured users may not view horizon outside local environments', function () {
    config([
        'app.env' => 'production',
        'horizon.access_emails' => ['admin@example.com'],
    ]);

    $user = User::factory()->create([
        'email' => 'other@example.com',
    ]);

    $this->actingAs($user)
        ->get('/horizon')
        ->assertForbidden();
});
