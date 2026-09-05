<?php

namespace App\Services;

use App\Models\DashboardPermissionProfile;
use App\Models\User;

/**
 * The branch dashboard has the same screens as the platform dashboard, but
 * not the same authority. This service is the single policy for capabilities
 * that are meaningful locally and for capabilities that would alter the
 * shared platform or another branch.
 */
final class BranchDashboardAuthorization
{
    /** @var array<string, array<int, string>> */
    private const BRANCH_MANAGER_ONLY = [
        'branches' => ['view', 'edit'],
        'employees' => ['view', 'create', 'edit', 'change_status', 'delete'],
        'permissions' => ['view', 'create', 'edit', 'delete', 'assign'],
    ];

    /** @var array<string, array<int, string>> */
    private const LOCAL_PROFILE_ACTIONS = [
        'orders' => ['view', 'view_financial', 'edit', 'change_status', 'assign_courier', 'reoffer_overdue_pickup', 'restore', 'delete'],
        'merchants' => ['view', 'edit', 'change_status', 'verify', 'documents_view', 'documents_review', 'delete'],
        'couriers' => ['view', 'edit', 'update_deduction', 'change_status', 'verify', 'documents_view', 'documents_review', 'delete'],
        'courier_locations' => ['view'],
        'finance' => ['view', 'view_requests', 'view_ledger', 'view_summary', 'view_balances', 'approve', 'reject', 'record_settlement'],
        'cashboxes' => ['view', 'view_balances', 'view_ledger', 'create', 'transfer', 'change_status'],
        'pricing' => ['view', 'create', 'edit', 'change_status'],
        'reports' => ['view', 'view_financial'],
        'notifications' => ['view', 'send'],
        // These settings are served through BranchSettingsResolver, which
        // writes only a branch-owned override and falls back to the platform
        // default. Governorates and every platform/network control remain
        // outside this local permission surface.
        'settings' => [
            'view',
            // Kept only for the old combined settings form. Its controller
            // path is branch-scoped and strips legal content before saving.
            'update',
            'update_branding',
            'update_support',
            'update_financial_defaults',
            'update_courier_deduction_default',
            'update_timing',
            'update_public_content',
        ],
        'content' => ['view', 'create', 'edit', 'delete'],
        'loyalty' => ['view', 'adjust_points'],
        'chat' => ['view', 'reply'],
        'transfers' => ['view', 'create', 'dispatch', 'receive'],
    ];

    public function allows(User $user, BranchDashboardScope $scope, string $module, string $action): bool
    {
        if (! $scope->hasBranchScope()) {
            return false;
        }

        if (in_array($action, self::BRANCH_MANAGER_ONLY[$module] ?? [], true)) {
            return $this->isBranchManager($user);
        }

        if (! in_array($action, self::LOCAL_PROFILE_ACTIONS[$module] ?? [], true)) {
            return false;
        }

        if ($this->isBranchManager($user)) {
            return true;
        }

        $profile = $user->permissionProfile;

        return $profile
            && (int) $profile->branch_id === (int) $scope->branchId()
            && $profile->allows($module, $action);
    }

    public function isBranchManager(User $user): bool
    {
        // A branch's original manager deliberately has no mutable profile:
        // assigning a profile is how the account is converted into a
        // restricted local employee. This avoids a second hidden privilege
        // flag and keeps the account visibly protected in the employee list.
        return $user->role === 'branch_manager' && $user->permission_profile_id === null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function effectivePermissions(User $user, BranchDashboardScope $scope): array
    {
        if (! $scope->hasBranchScope()) {
            return [];
        }

        $permissions = [];

        foreach (DashboardPermissionProfile::MODULES as $module => $definition) {
            $actions = array_keys($definition['actions']);
            $granted = array_values(array_filter(
                $actions,
                fn (string $action): bool => $this->allows($user, $scope, $module, $action),
            ));

            if ($granted !== []) {
                $permissions[$module] = $granted;
            }
        }

        return $permissions;
    }

    /**
     * Catalog used while a branch manager creates profiles for local staff.
     * It never exposes controls which a profile could not actually use.
     *
     * @return array<int, array<string, mixed>>
     */
    public function localProfileCatalog(): array
    {
        return collect(DashboardPermissionProfile::catalog())
            ->map(function (array $module): ?array {
                $allowedActions = self::LOCAL_PROFILE_ACTIONS[$module['key']] ?? [];
                if ($allowedActions === []) {
                    return null;
                }

                $actions = collect($module['actions'])
                    ->filter(fn (array $action): bool => in_array($action['key'], $allowedActions, true))
                    ->values()
                    ->all();

                if ($actions === []) {
                    return null;
                }

                $module['actions'] = $actions;
                $module['action_labels'] = collect($module['action_labels'])
                    ->only(array_column($actions, 'key'))
                    ->all();

                return $module;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $permissions
     * @return array<string, array<int, string>>
     */
    public function normalizeLocalProfilePermissions(array $permissions): array
    {
        $normalized = DashboardPermissionProfile::normalizePermissions($permissions);

        return collect($normalized)
            ->map(function (array $actions, string $module): array {
                return array_values(array_filter(
                    $actions,
                    fn (string $action): bool => in_array($action, self::LOCAL_PROFILE_ACTIONS[$module] ?? [], true),
                ));
            })
            ->filter(fn (array $actions): bool => $actions !== [])
            ->all();
    }
}
