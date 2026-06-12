<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\GitHubController;
use App\Http\Middleware\EnsureOnboarded;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('/auth/github/redirect', [GitHubController::class, 'redirect'])->name('auth.github.redirect');
Route::get('/auth/github/callback', [GitHubController::class, 'callback'])->name('auth.github.callback');

Route::middleware(['auth', 'verified'])
    ->group(function () {
        Route::livewire('onboarding', 'pages::onboarding')->name('onboarding');
    });

Route::middleware(['auth', 'verified', EnsureTeamMembership::class, EnsureOnboarded::class])
    ->group(function () {
        Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
        Route::livewire('briefs', 'pages::briefs')->name('briefs');
        Route::get('attachments/{attachment}', AttachmentController::class)->name('attachments.show');
    });

Route::middleware(['auth'])->group(function () {
    Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')->name('invitations.accept');
});

require __DIR__.'/settings.php';
