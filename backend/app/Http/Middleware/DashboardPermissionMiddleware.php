<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\BranchDashboardAuthorization;
use App\Services\BranchDashboardContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces one server-owned dashboard capability.  Route middleware remains
 * the source of truth; client navigation may hide unavailable modules, but
 * it never authorizes a request on its own.
 */
class DashboardPermissionMiddleware
{
    public function __construct(
        private readonly BranchDashboardContext $branchContext,
        private readonly BranchDashboardAuthorization $branchAuthorization,
    ) {
    }

    public function handle(Request $request, Closure $next, string $moduleAndAction, ?string $action = null): Response
    {
        [$module, $resolvedAction] = $this->parsePermission($moduleAndAction, $action);
        $user = $request->user();

        if ($user instanceof User) {
            $scope = $this->branchContext->fromRequest($request);

            if ($scope->requiresBranchScope()) {
                abort_unless(
                    $user->isActiveUser()
                        && $scope->hasBranchScope()
                        && $this->branchAuthorization->allows($user, $scope, $module, $resolvedAction),
                    403,
                );

                return $next($request);
            }
        }

        abort_unless(
            $user
                && $user->isAdmin()
                && $user->isActiveUser()
                && $user->canUseAdminPermission($module, $resolvedAction),
            403,
        );

        // Permission profiles are intentionally not self-administrable. A
        // delegated user could otherwise attach a more powerful profile to
        // themselves and bypass every row in the matrix.
        if ($module === 'permissions') {
            abort_unless($user->isSuperAdmin(), 403);
        }

        return $next($request);
    }

    /** @return array{string, string} */
    private function parsePermission(string $moduleAndAction, ?string $action): array
    {
        if ($action !== null) {
            return [$moduleAndAction, $action];
        }

        $parts = explode('.', $moduleAndAction, 2);

        return [$parts[0], $parts[1] ?? 'view'];
    }
}
