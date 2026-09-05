<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchMembership;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Resolves the one server-owned branch boundary for the full dashboard.
 *
 * Super administrators deliberately receive an unrestricted scope. All
 * owner/branch-manager accounts must resolve to one active platform branch;
 * the service never accepts a branch id from the browser as proof of access.
 */
final class BranchDashboardContext
{
    public const REQUEST_ATTRIBUTE = 'branch_dashboard_scope';

    public function scopeFor(User $user): BranchDashboardScope
    {
        if ($user->isSuperAdmin()) {
            return BranchDashboardScope::superAdmin($user);
        }

        if (! $this->requiresBranchScope($user)) {
            return BranchDashboardScope::notApplicable($user);
        }

        if (! $user->isActiveUser()) {
            return BranchDashboardScope::unavailableBranchAccount($user);
        }

        $membership = $this->primaryMembership($user);

        if ($membership) {
            return BranchDashboardScope::forBranch($user, $membership->branch);
        }

        return BranchDashboardScope::unavailableBranchAccount($user);
    }

    /**
     * Prefer the scope cached by middleware, while preserving an explicit
     * fallback for jobs, policies, and focused controller tests.
     */
    public function fromRequest(Request $request): BranchDashboardScope
    {
        $cached = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if ($cached instanceof BranchDashboardScope) {
            return $cached;
        }

        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthorizationException('An authenticated dashboard user is required.');
        }

        return $this->scopeFor($user);
    }

    public function hasActiveBranchAccess(User $user): bool
    {
        $scope = $this->scopeFor($user);

        return $scope->requiresBranchScope() && $scope->hasBranchScope();
    }

    /**
     * Return the local branch for a branch dashboard account. Super admins
     * intentionally have no implicit branch; they must use a deliberate
     * filter if a screen needs one.
     */
    public function requireBranch(User $user): Branch
    {
        $scope = $this->scopeFor($user);

        if (! $scope->hasBranchScope()) {
            throw new AuthorizationException('This dashboard account is not assigned to an active branch.');
        }

        return $scope->branch();
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function restrict(Builder $query, User $user, string $qualifiedBranchColumn = 'branch_id'): Builder
    {
        return $this->scopeFor($user)->restrict($query, $qualifiedBranchColumn);
    }

    /**
     * Make this branch the sole primary dashboard assignment for the user.
     * Secondary historical memberships may remain for audit purposes, but
     * they cannot expand the dashboard boundary.
     */
    public function assignPrimaryMembership(User $user, Branch $branch): BranchMembership
    {
        if (! $this->requiresBranchScope($user)) {
            throw new InvalidArgumentException('Only branch dashboard accounts can receive a branch membership.');
        }

        if ((int) $user->tenant_id !== (int) Tenant::platform()->id) {
            throw new InvalidArgumentException('A branch dashboard account must belong to the platform tenant.');
        }

        if (! $this->isValidPlatformBranch($branch)) {
            throw new InvalidArgumentException('The selected branch is not an active platform branch.');
        }

        $accessRole = $this->accessRoleFor($user);
        $membership = BranchMembership::assignPrimary($user, $branch, $accessRole);

        // Keep the old branch_id as a compatibility pointer for mobile and
        // historical integrations. Authorisation always comes from the
        // explicit membership above.
        if ((int) $user->branch_id !== (int) $branch->id) {
            $user->forceFill(['branch_id' => $branch->id])->save();
        }

        return $membership;
    }

    public function requiresBranchScope(User $user): bool
    {
        return in_array($user->role, ['owner', 'branch_manager'], true);
    }

    private function accessRoleFor(User $user): string
    {
        return $user->role === 'owner'
            ? BranchMembership::OWNER
            : BranchMembership::MANAGER;
    }

    private function primaryMembership(User $user): ?BranchMembership
    {
        $accessRole = $this->accessRoleFor($user);

        $declaredPrimary = BranchMembership::query()
            ->with('branch')
            ->where('user_id', $user->id)
            ->where('access_role', $accessRole)
            ->where('is_primary', true)
            ->where('primary_user_id', $user->id)
            ->first();

        if ($declaredPrimary) {
            return $this->isValidPlatformBranch($declaredPrimary->branch)
                ? $declaredPrimary
                : null;
        }

        // Existing accounts were provisioned before primary memberships were
        // introduced. A temporary compatibility path is safe only when their
        // role has exactly one valid branch membership; multiple memberships
        // are denied until an administrator explicitly chooses a primary.
        $legacyMemberships = $this->validMemberships($user, $accessRole);

        return $legacyMemberships->count() === 1
            ? $legacyMemberships->first()
            : null;
    }

    /** @return Collection<int, BranchMembership> */
    private function validMemberships(User $user, string $accessRole): Collection
    {
        return BranchMembership::query()
            ->with('branch')
            ->where('user_id', $user->id)
            ->where('access_role', $accessRole)
            ->whereIn('branch_id', $this->activePlatformBranchIds())
            ->orderBy('branch_id')
            ->get();
    }

    /** @return Builder<Branch> */
    private function activePlatformBranchIds(): Builder
    {
        return Branch::withoutGlobalScope(TenantScope::class)
            ->select('id')
            ->where('tenant_id', Tenant::platform()->id)
            ->where('is_platform_managed', true)
            ->where('is_active', true)
            ->where(function (Builder $branches): void {
                // Historic rows without a province remain usable until an
                // operator assigns one. Once a province exists, the durable
                // unique operational key must agree with it.
                $branches
                    ->whereNull('province_id')
                    ->orWhereColumn('active_platform_province_id', 'province_id');
            });
    }

    private function isValidPlatformBranch(?Branch $branch): bool
    {
        if (! $branch
            || ! $branch->is_platform_managed
            || ! $branch->is_active
            || (int) $branch->tenant_id !== (int) Tenant::platform()->id) {
            return false;
        }

        // A historic branch without a governorate remains usable only as a
        // compatibility path. New primary assignments require an operational
        // province through assignPrimaryMembership().
        return $branch->province_id === null
            || (int) $branch->active_platform_province_id === (int) $branch->province_id;
    }
}
