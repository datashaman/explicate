<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GitHubController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('github')
            ->scopes(['repo', 'read:user'])
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $githubUser = Socialite::driver('github')->user();
        } catch (\Exception) {
            return redirect()->route('login')->withErrors(['github' => __('GitHub authorization was denied or failed.')]);
        }

        if (Auth::check()) {
            Auth::user()->update([
                'github_id' => $githubUser->getId(),
                'github_nickname' => $githubUser->getNickname(),
                'github_token' => $githubUser->token,
            ]);

            return redirect()->route('profile.edit')->with('status', 'github-connected');
        }

        $email = $githubUser->getEmail()
            ?? $githubUser->getId().'+'.$githubUser->getNickname().'@users.noreply.github.com';

        $user = User::updateOrCreate(
            ['github_id' => $githubUser->getId()],
            [
                'name' => $githubUser->getName() ?? $githubUser->getNickname(),
                'email' => $email,
                'github_nickname' => $githubUser->getNickname(),
                'github_token' => $githubUser->token,
                'password' => Str::password(32),
            ]
        );

        Auth::login($user);

        return redirect()->intended(route('dashboard'));
    }
}
