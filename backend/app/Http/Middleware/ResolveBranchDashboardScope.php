<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\BranchDashboardContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves and caches the server-owned branch boundary for dashboard routes.
 *
 * The middleware deliberately leaves super administrators and ordinary
 * profile-based employees alone. It only rejects branch dashboard accounts
 * whose primary branch assignment was paused, removed, or made ambiguous.
 */
class ResolveBranchDashboardScope
{
    public function __construct(private readonly BranchDashboardContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $scope = $this->context->scopeFor($user);

        if (! $scope->requiresBranchScope()) {
            return $next($request);
        }

        if (! $scope->isAvailable()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/dashboard/login')->withErrors([
                'username' => 'تم إيقاف هذا الفرع أو لم تعد صلاحية الدخول إليه متاحة.',
            ]);
        }

        $request->attributes->set(BranchDashboardContext::REQUEST_ATTRIBUTE, $scope);

        return $next($request);
    }
}
