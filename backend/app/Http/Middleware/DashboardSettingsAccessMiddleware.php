<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\BranchDashboardAuthorization;
use App\Services\BranchDashboardContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The slider and governorates are managed inside Settings but retain their
 * own capability modules. This keeps a content- or province-only operator
 * on the relevant tab without exposing the rest of platform configuration.
 */
class DashboardSettingsAccessMiddleware
{
    public function __construct(
        private readonly BranchDashboardContext $branchContext,
        private readonly BranchDashboardAuthorization $branchAuthorization,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $scope = $this->branchContext->fromRequest($request);

            if ($scope->requiresBranchScope()) {
                abort_unless(
                    $user->isActiveUser()
                        && $scope->hasBranchScope()
                        && (
                            $this->branchAuthorization->allows($user, $scope, 'settings', 'view')
                            || $this->branchAuthorization->allows($user, $scope, 'content', 'view')
                        ),
                    403,
                );

                return $next($request);
            }
        }

        abort_unless(
            $user
                && $user->isAdmin()
                && $user->isActiveUser()
                && (
                    $user->canUseAdminPermission('settings', 'view')
                    || $user->canUseAdminPermission('content', 'view')
                    || $user->canUseAdminPermission('provinces', 'view')
                ),
            403,
        );

        return $next($request);
    }
}
