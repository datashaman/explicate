<?php

use App\Http\Middleware\EnsureTeamMembership;
use App\Models\Agent;
use App\Models\Message;
use App\Models\Topic;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::bind('topic', function (string $value): Topic {
    $workspace = request()->user()?->currentWorkspace;

    abort_unless($workspace, 404);

    return $workspace->topics()
        ->where('slug', $value)
        ->firstOrFail();
});

Route::bind('message', function (string $value): Message {
    $workspace = request()->user()?->currentWorkspace;

    abort_unless($workspace, 404);

    return Message::query()
        ->where('ulid', $value)
        ->whereHas('topic', fn ($query) => $query->where('workspace_id', $workspace->id))
        ->firstOrFail();
});

Route::bind('agent', function (string $value): Agent {
    $workspace = request()->user()?->currentWorkspace;

    abort_unless($workspace, 404);

    return $workspace->agents()
        ->where('slug', $value)
        ->firstOrFail();
});

Route::middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
        Route::livewire('messages/new', 'pages::dashboard')->name('messages.create');
        Route::livewire('topics/{topic}', 'pages::topic')->name('topics.show');
        Route::livewire('messages/{message}', 'pages::message')->name('messages.show');
        Route::livewire('agents', 'pages::agents')->name('agents');
        Route::livewire('agents/{agent}', 'pages::agent')->name('agents.show');
    });

Route::middleware(['auth'])->group(function () {
    Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')->name('invitations.accept');
});

require __DIR__.'/settings.php';
