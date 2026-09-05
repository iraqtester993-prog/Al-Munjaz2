<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\BranchDashboardAuthorization;
use App\Services\BranchDashboardContext;
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
    public function __construct(
        private readonly BranchDashboardContext $branchContext,
        private readonly BranchDashboardAuthorization $branchAuthorization,
    ) {
    }

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

        if ($actor instanceof User) {
            $scope = $this->branchContext->fromRequest($request);

            if ($scope->requiresBranchScope()) {
                abort_unless($scope->hasBranchScope(), 403);

                // A route-bound id is never an authorisation boundary. Read
                // the subject again under the branch's SQL restriction before
                // a controller can inspect or mutate it.
                $subject = $scope->restrictUsers(User::query())
                    ->whereKey($subject->id)
                    ->where(function ($people) use ($module): void {
                        if ($module === 'merchants') {
                            $people->where('role', 'merchant');

                            return;
                        }

                        $people->whereIn('role', User::COURIER_ROLES);
                    })
                    ->firstOrFail();
                $request->route()->setParameter('user', $subject);

                abort_unless(
                    $actor->isActiveUser()
                        && $this->branchAuthorization->allows($actor, $scope, $module, $action),
                    403,
                );

                return $next($request);
            }
        }

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
