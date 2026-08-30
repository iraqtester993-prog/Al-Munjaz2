<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards dashboard responses that aggregate several independent platform
 * modules. Until that payload is separately filtered per module, allowing a
 * limited profile to open it would disclose more than its matrix permits.
 */
class DashboardSuperAdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isSuperAdmin() && $request->user()->isActiveUser(), 403);

        return $next($request);
    }
}
