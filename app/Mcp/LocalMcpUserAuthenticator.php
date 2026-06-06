<?php

namespace App\Mcp;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\Auth;

class LocalMcpUserAuthenticator
{
    public function __construct(protected AuthFactory $auth) {}

    public function authenticate(): ?User
    {
        if ($this->detectTransport() !== 'local') {
            return null;
        }

        $identifier = config('mcp.local_auth_user');

        if (blank($identifier)) {
            return null;
        }

        $field = config('mcp.local_auth_field');

        $user = User::query()
            ->when(
                filled($field),
                fn ($query) => $query->where((string) $field, $identifier),
                fn ($query) => is_numeric($identifier)
                    ? $query->whereKey((int) $identifier)
                    : $query->where('email', $identifier)
            )
            ->first();

        if (! $user instanceof User) {
            throw new AuthenticationException('The configured MCP local auth user could not be found.');
        }

        Auth::login($user);

        return $user;
    }

    private function detectTransport(): ?string
    {
        if (app()->runningInConsole()) {
            return 'local';
        }

        return request()?->isJson() ? 'http' : null;
    }
}
