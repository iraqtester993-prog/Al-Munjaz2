<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\BranchDashboardAuthorization;
use App\Services\BranchDashboardContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards dashboard responses that aggregate several independent platform
 * modules. Until that payload is separately filtered per module, allowing a
 * limited profile to open it would disclose more than its matrix permits.
 */
class DashboardSuperAdminMiddleware
{
    public function __construct(
        private readonly BranchDashboardContext $branchContext,
        private readonly BranchDashboardAuthorization $branchAuthorization,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isActiveUser()) {
            if ($user->isSuperAdmin()) {
                return $next($request);
            }

            // The full dashboard landing page contains cross-module cards.
            // It is available to the branch's principal manager only; a
            // profile-based local employee starts at their granted module.
            $scope = $this->branchContext->fromRequest($request);
            if ($scope->hasBranchScope() && $this->branchAuthorization->isBranchManager($user)) {
                return $next($request);
            }
        }

        abort(403);
    }
}
