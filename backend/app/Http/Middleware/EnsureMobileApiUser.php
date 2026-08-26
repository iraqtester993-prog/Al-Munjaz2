<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The versioned API is the mobile/application API, not a second route into
 * the branch portal. Keep dashboard-only accounts out even if they have a
 * previously issued Sanctum token.
 */
class EnsureMobileApiUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user
                && $user->isActiveUser()
                && ($user->isAdmin() || $user->role === 'merchant' || $user->isCourierRole()),
            403,
        );

        return $next($request);
    }
}
