<?php

namespace App\Http\Controllers;

use App\Actions\Onboarding\SetupNewUser;
use App\Actions\Teams\CreateTeam;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GitHubController extends Controller
{
    public function __construct(
        private CreateTeam $createTeam,
        private SetupNewUser $setupNewUser,
    ) {}

    public function redirect(): RedirectResponse
    {
        return Socialite::driver('github')
            ->scopes(['repo', 'read:user', 'user:email'])
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

        $existing = User::where('github_id', $githubUser->getId())
            ->orWhere('email', $email)
            ->first();

        $isNew = $existing === null;

        $user = DB::transaction(function () use ($githubUser, $email, $existing) {
            if ($existing) {
                $existing->update([
                    'github_id' => $githubUser->getId(),
                    'github_nickname' => $githubUser->getNickname(),
                    'github_token' => $githubUser->token,
                ]);

                return $existing;
            }

            $user = User::create([
                'name' => $githubUser->getName() ?? $githubUser->getNickname(),
                'email' => $email,
                'github_id' => $githubUser->getId(),
                'github_nickname' => $githubUser->getNickname(),
                'github_token' => $githubUser->token,
                'password' => Str::password(32),
            ]);

            $this->createTeam->handle($user, $user->name."'s Team", isPersonal: true);
            $this->setupNewUser->handle($user);

            return $user;
        });

        Auth::login($user);

        return redirect()->intended(route('dashboard'));
    }
}
