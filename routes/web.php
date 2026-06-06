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
    $topic = request()->route('topic');

    abort_unless($topic instanceof Topic, 404);

    return $topic->messages()
        ->where('slug', $value)
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
        Route::get('messages/new', function () {
            $parameters = [
                'action' => 'new-message',
                'panel' => 'messages',
            ];

            if (request()->filled('topic')) {
                $parameters['topic'] = request()->query('topic');
            }

            return redirect()->route('dashboard', $parameters);
        })->name('messages.create');
        Route::livewire('topics/{topic}', 'pages::topic')->name('topics.show');
        Route::livewire('topics/{topic}/messages/{message}', 'pages::message')->name('messages.show');
        Route::livewire('agents', 'pages::agents')->name('agents');
        Route::livewire('agents/{agent}', 'pages::agent')->name('agents.show');
    });

Route::middleware(['auth'])->group(function () {
    Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')->name('invitations.accept');
});

require __DIR__.'/settings.php';
