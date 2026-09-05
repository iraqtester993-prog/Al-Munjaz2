<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Resolves the optional branch filter available to the platform super admin.
 *
 * A branch dashboard never trusts a branch id supplied by the browser: its
 * primary membership remains the only active boundary. Platform staff other
 * than the super admin retain their existing permission model and do not gain
 * a cross-branch filter through this helper.
 */
final class DashboardBranchFilter
{
    /**
     * @return array{enabled:bool,selected_id:?int,branches:array<int, array{id:int,name_ar:?string,name_en:?string,name_ku:?string,city:?string,is_active:bool}>}
     */
    public function payload(Request $request, BranchDashboardScope $scope): array
    {
        if (! $scope->isSuperAdmin()) {
            return [
                'enabled' => false,
                'selected_id' => $scope->hasBranchScope() ? $scope->branchId() : null,
                'branches' => [],
            ];
        }

        return [
            'enabled' => true,
            'selected_id' => $this->selectedBranchId($request, $scope),
            'branches' => $this->platformBranches()
                ->get(['id', 'name_ar', 'name_en', 'name_ku', 'city', 'is_active'])
                ->map(fn (Branch $branch): array => [
                    'id' => (int) $branch->id,
                    'name_ar' => $branch->name_ar,
                    'name_en' => $branch->name_en,
                    'name_ku' => $branch->name_ku,
                    'city' => $branch->city,
                    'is_active' => (bool) $branch->is_active,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Return the server-validated selected branch id. A null value means
     * "all branches" for a super admin, while a branch user is always pinned
     * to their membership even if they tamper with the query string.
     */
    public function selectedBranchId(Request $request, BranchDashboardScope $scope): ?int
    {
        if ($scope->hasBranchScope()) {
            return $scope->branchId();
        }

        if (! $scope->isSuperAdmin()) {
            return null;
        }

        $data = $request->validate([
            'branch_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('branches', 'id')->where(function ($branches): void {
                    $branches
                        ->where('tenant_id', Tenant::platform()->id)
                        ->where('is_platform_managed', true)
                        // `withoutGlobalScopes()` below is intentional for
                        // the cross-tenant dashboard. Keep a deleted branch
                        // out of both the selector and a hand-crafted URL.
                        ->whereNull('deleted_at');
                }),
            ],
        ]);

        if (! isset($data['branch_id'])) {
            return null;
        }

        return (int) $data['branch_id'];
    }

    /** @return Builder<Branch> */
    public function platformBranches(): Builder
    {
        return Branch::withoutGlobalScopes()
            ->where('tenant_id', Tenant::platform()->id)
            ->where('is_platform_managed', true)
            // Super-admin historical review deliberately includes disabled
            // branches, but never a soft-deleted branch.
            ->whereNull('deleted_at')
            ->orderBy('name_ar')
            ->orderBy('id');
    }

    /** @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function restrictByColumn(Builder $query, ?int $branchId, string $qualifiedColumn = 'branch_id'): Builder
    {
        return $branchId ? $query->where($qualifiedColumn, $branchId) : $query;
    }

    /** @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function restrictOrders(Builder $query, ?int $branchId): Builder
    {
        if (! $branchId) {
            return $query;
        }

        $model = $query->getModel();

        return $query->where(function (Builder $orders) use ($model, $branchId): void {
            $orders
                ->where($model->qualifyColumn('origin_branch_id'), $branchId)
                ->orWhere($model->qualifyColumn('destination_branch_id'), $branchId)
                ->orWhere($model->qualifyColumn('branch_id'), $branchId);
        });
    }
}
