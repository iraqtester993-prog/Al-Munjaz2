<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDashboardHost
{
    /**
     * Keep administration routes isolated from the public application host.
     * This also repairs older installed PWAs that may still open /dashboard.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment(['local', 'testing']) && ! str_starts_with($request->getHost(), 'dashboard.')) {
            return redirect('/login');
        }

        return $next($request);
    }
}
