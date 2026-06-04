<?php

use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
        Route::livewire('dashboard/new-message', 'pages::message-create')->name('messages.create');
        Route::livewire('dashboard/{topic}', 'pages::topic')->name('topics.show');
        Route::livewire('dashboard/{topic}/{message}', 'pages::message')->name('messages.show');
        Route::livewire('agents', 'pages::agents')->name('agents');
        Route::livewire('agents/{agent}', 'pages::agent')->name('agents.show');
    });

Route::middleware(['auth'])->group(function () {
    Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')->name('invitations.accept');
});

require __DIR__.'/settings.php';
