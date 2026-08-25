<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        if (in_array(Auth::user()->role, $roles, true)) {
            return $next($request);
        }

        // The public PWA and the administration panel deliberately use
        // separate hosts. A user who opens the other product must be sent to
        // its own sign-in page, never left on a generic 403 screen.
        if (Auth::user()->role === 'admin' && in_array('merchant', $roles, true)) {
            $host = $request->getHost();
            $baseDomain = preg_replace('/^(?:app|dashboard|mobile|admin)\./', '', $host);

            return redirect()->away($request->getScheme().'://admin.'.$baseDomain.'/dashboard/login');
        }

        if (in_array('admin', $roles, true)) {
            $host = $request->getHost();
            $baseDomain = preg_replace('/^(?:app|dashboard|mobile|admin)\./', '', $host);

            return redirect()->away($request->getScheme().'://mobile.'.$baseDomain.'/login');
        }

        abort(403, __('auth.unauthorized'));
    }
}
