<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves a generic /dashboard/users/{user} route to the narrower merchant
 * or courier capability.  A static permission on those routes would let a
 * merchant-only operator modify a courier (or the opposite) merely by
 * changing an id in the URL.
 */
class DashboardUserPermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $action): Response
    {
        $subject = $request->route('user');
        $subject = $subject instanceof User
            ? $subject
            : User::query()->findOrFail($subject);

        $module = match (true) {
            $subject->role === 'merchant' => 'merchants',
            $subject->isCourierRole() => 'couriers',
            default => null,
        };

        abort_unless($module !== null, 404);

        $actor = $request->user();
        abort_unless(
            $actor
                && $actor->isAdmin()
                && $actor->isActiveUser()
                && $actor->canUseAdminPermission($module, $action),
            403,
        );

        return $next($request);
    }
}
