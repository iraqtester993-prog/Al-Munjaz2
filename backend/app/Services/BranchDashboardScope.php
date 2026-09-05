<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Immutable, server-owned boundary for a dashboard request.
 *
 * A branch account receives exactly one operational branch here.  It is
 * deliberately not represented by a request parameter, selected filter, or
 * front-end store: those are all client-controlled and therefore unsuitable
 * as an authorisation boundary.
 */
final readonly class BranchDashboardScope
{
    private function __construct(
        private User $user,
        private ?Branch $branch,
        private bool $superAdmin,
        private bool $branchAccount,
    ) {}

    public static function superAdmin(User $user): self
    {
        return new self($user, null, true, false);
    }

    public static function forBranch(User $user, Branch $branch): self
    {
        return new self($user, $branch, false, true);
    }

    /**
     * A branch account without one valid primary membership must not inherit
     * broad dashboard access while an administrator repairs its assignment.
     */
    public static function unavailableBranchAccount(User $user): self
    {
        return new self($user, null, false, true);
    }

    /**
     * A regular platform employee is governed by the existing permission
     * profile middleware, rather than by a branch membership.
     */
    public static function notApplicable(User $user): self
    {
        return new self($user, null, false, false);
    }

    public function user(): User
    {
        return $this->user;
    }

    public function isSuperAdmin(): bool
    {
        return $this->superAdmin;
    }

    /**
     * True only for owner/branch-manager accounts.  Middleware uses this to
     * distinguish a broken branch account from a normal limited employee.
     */
    public function requiresBranchScope(): bool
    {
        return $this->branchAccount;
    }

    public function hasBranchScope(): bool
    {
        return $this->branch !== null;
    }

    public function isAvailable(): bool
    {
        return $this->isSuperAdmin() || $this->hasBranchScope();
    }

    public function branch(): ?Branch
    {
        return $this->branch;
    }

    public function branchId(): ?int
    {
        return $this->branch?->getKey();
    }

    public function allowsBranch(Branch|int $branch): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->hasBranchScope()
            && $this->branchId() === (int) ($branch instanceof Branch ? $branch->getKey() : $branch);
    }

    /**
     * Scope a branch-owned model using a model-qualified branch column.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function restrict(Builder $query, string $qualifiedBranchColumn = 'branch_id'): Builder
    {
        if ($this->isSuperAdmin()) {
            return $query;
        }

        if (! $this->hasBranchScope()) {
            throw new AuthorizationException('A valid branch dashboard scope is required.');
        }

        return $query->where($qualifiedBranchColumn, $this->branchId());
    }

    /**
     * Scope an operational order to either endpoint of the active branch.
     * The legacy branch_id remains included because historical orders may
     * predate the origin/destination branch workflow.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function restrictOrders(Builder $query): Builder
    {
        if ($this->isSuperAdmin()) {
            return $query;
        }

        if (! $this->hasBranchScope()) {
            throw new AuthorizationException('A valid branch dashboard scope is required.');
        }

        $model = $query->getModel();
        $branchId = $this->branchId();

        return $query->where(function (Builder $orders) use ($model, $branchId): void {
            $orders
                ->where($model->qualifyColumn('origin_branch_id'), $branchId)
                ->orWhere($model->qualifyColumn('destination_branch_id'), $branchId)
                ->orWhere($model->qualifyColumn('branch_id'), $branchId);
        });
    }

    /**
     * Scope merchants, couriers, and branch-local employees to the active
     * branch. The caller remains responsible for its role/status predicate.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function restrictUsers(Builder $query): Builder
    {
        return $this->restrict($query, $query->getModel()->qualifyColumn('branch_id'));
    }
}
