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
        $isDashboardPath = $request->is('dashboard') || $request->is('dashboard/*') || $request->is('admin');

        if ($isDashboardPath && ! app()->environment(['local', 'testing']) && ! preg_match('/^(?:dashboard|admin)\./', $request->getHost())) {
            return redirect('/login');
        }

        return $next($request);
    }
}
