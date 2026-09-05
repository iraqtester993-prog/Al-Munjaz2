<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the final branch boundary after ordinary authentication.
 *
 * A branch manager can remain an active user while the branch itself is
 * paused. In that situation their user record alone must not keep a saved
 * dashboard URL usable. This middleware is deliberately attached to every
 * branch portal route rather than relying only on the overview page.
 */
class EnsureActiveBranchPortalAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->hasActiveBranchPortalAccess()) {
            return $next($request);
        }

        // Drop the current server-side session as well as the browser's
        // authenticated state. Reactivating a branch later permits a fresh
        // sign-in, but never revives a stale session automatically.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/dashboard/login')->withErrors([
            'username' => 'تم إيقاف هذا الفرع أو لم تعد صلاحية الدخول إليه متاحة.',
        ]);
    }
}
