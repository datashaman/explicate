<?php

namespace App\Mcp\Resources\Concerns;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Laravel\Mcp\Response;

trait HandlesResourceExceptions
{
    /**
     * @param  Closure(): Response  $callback
     */
    protected function guardResource(Closure $callback): Response
    {
        try {
            return $callback();
        } catch (AuthenticationException|AuthorizationException $exception) {
            logger()->error('Auth Error', ['exception' => $exception]);

            return Response::error($exception->getMessage());
        }
    }
}
