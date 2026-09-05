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
            return redirect()->away($this->productScheme($request).'://'.config('app.product_admin_host').'/dashboard/login');
        }

        if (Auth::user()->role === 'branch_manager' && in_array('merchant', $roles, true)) {
            return redirect()->away($this->productScheme($request).'://'.config('app.product_admin_host').'/dashboard');
        }

        if (Auth::user()->role === 'owner' && in_array('merchant', $roles, true)) {
            return redirect()->away($this->productScheme($request).'://'.config('app.product_admin_host').'/dashboard/branch');
        }

        if (Auth::user()->role === 'owner' && in_array('admin', $roles, true)) {
            return redirect()->away($this->productScheme($request).'://'.config('app.product_admin_host').'/dashboard/branch');
        }

        // Branch managers use the unified dashboard. A bookmarked legacy
        // owner-portal URL must not bypass their primary branch boundary or
        // their local permission profile.
        if (Auth::user()->role === 'branch_manager' && in_array('owner', $roles, true)) {
            $path = Auth::user()->firstAdminDashboardPath() ?? '/dashboard/access-denied';

            return redirect()->away($this->productScheme($request).'://'.config('app.product_admin_host').$path);
        }

        if (Auth::user()->role === 'branch_manager' && in_array('admin', $roles, true)) {
            return redirect()->away($this->productScheme($request).'://'.config('app.product_admin_host').'/dashboard');
        }

        if (in_array('admin', $roles, true)) {
            return redirect()->away($this->productScheme($request).'://'.config('app.product_mobile_host').'/login');
        }

        abort(403, __('auth.unauthorized'));
    }

    /**
     * The public hosts are HTTPS-only in every deployed environment.  A
     * reverse proxy may hand PHP an internal HTTP request, so using
     * Request::getScheme() here could send users to an insecure URL.
     */
    private function productScheme(Request $request): string
    {
        return app()->environment(['production', 'staging']) ? 'https' : $request->getScheme();
    }
}
