<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ActiveUserMiddleware
{
    /**
     * A heartbeat is only needed to show recent activity. Writing on every
     * request turns polling (chat, notifications, and Inertia navigation)
     * into needless database writes, so refresh it at most once per interval.
     */
    private const HEARTBEAT_INTERVAL_MINUTES = 5;

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && $user->status === 'active') {
            // `is_online` is the courier's explicit duty/availability flag,
            // not an authentication heartbeat. Updating it on every request
            // made the "غير متاح" toggle ineffective and let an offline
            // courier claim a new order merely by opening a page.
            $this->refreshHeartbeat($user);

            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Dashboard staff should return to the same dashboard sign-in
        // surface after their access is disabled, not to the mobile app's
        // merchant/courier login page.
        $loginRoute = $request->is('dashboard*') ? 'admin.login' : 'login';

        return redirect()->route($loginRoute)->withErrors([
            'username' => __('auth.account_inactive'),
        ]);
    }

    /**
     * Refresh the user's activity timestamp only when it is missing or stale.
     *
     * The conditional update protects against two simultaneous requests that
     * both received an old authenticated User instance. Only the first request
     * performs the write; subsequent requests see zero affected rows.
     */
    private function refreshHeartbeat(User $user): void
    {
        $now = now();
        $refreshBefore = $now->copy()->subMinutes(self::HEARTBEAT_INTERVAL_MINUTES);

        if ($user->last_active_at?->gt($refreshBefore)) {
            return;
        }

        $updated = $user->newQuery()
            ->whereKey($user->getKey())
            ->where(function ($query) use ($refreshBefore): void {
                $query->whereNull('last_active_at')
                    ->orWhere('last_active_at', '<=', $refreshBefore);
            })
            ->update([
                'last_active_at' => $now,
                'updated_at' => $now,
            ]);

        if ($updated) {
            // Keep the authenticated instance accurate for the remainder of
            // this request without triggering a second persistence operation.
            $user->setAttribute('last_active_at', $now);
            $user->setAttribute('updated_at', $now);
        }
    }
}
