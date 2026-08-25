<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ActiveUserMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && $user->status === 'active') {
            // `is_online` is the courier's explicit duty/availability flag,
            // not an authentication heartbeat. Updating it on every request
            // made the "غير متاح" toggle ineffective and let an offline
            // courier claim a new order merely by opening a page.
            $user->forceFill(['last_active_at' => now()])->saveQuietly();

            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors([
            'username' => __('auth.account_inactive'),
        ]);
    }
}
