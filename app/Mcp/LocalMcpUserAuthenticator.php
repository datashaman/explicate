<?php

namespace App\Mcp;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

class LocalMcpUserAuthenticator
{
    public function __construct(protected AuthFactory $auth) {}

    public function authenticate(): void
    {
        if ($this->auth->guard('web')->check()) {
            return;
        }

        $identifier = config('mcp.local_auth_user');

        if (blank($identifier)) {
            return;
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

        $this->auth->guard('web')->setUser($user);
        $this->auth->shouldUse('web');
    }
}
