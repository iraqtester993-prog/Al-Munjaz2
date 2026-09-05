<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Visibility rules used by the courier application.
 *
 * Orders belong to the merchant tenant, while a courier has an independent
 * tenant.  The normal tenant scope therefore cannot be used for courier
 * operational queues.  Every query in this class explicitly restricts the
 * order by its state, assignment and the courier's authorised provinces.
 */
class CourierOrderAccess
{
    public function assigned(User $courier): Builder
    {
        // New and active direct orders have one accountable courier. The
        // pickup/delivery columns are retained for legacy history only and do
        // not grant operational visibility or mutation rights.
        if ($courier->role !== 'courier') {
            return $this->base()->whereRaw('1 = 0');
        }

        return $this->base()->where('courier_id', $courier->id);
    }

    public function available(User $courier): Builder
    {
        if ($courier->role !== 'courier' || ! $courier->isCourierVerified()) {
            return $this->base()->whereRaw('1 = 0');
        }

        $provinceIds = $this->provinceIds($courier);
        $branchId = $this->operatingBranchId($courier);
        $registeredAt = $courier->created_at;

        $orders = $this->base()
            ->where('status', 'pending')
            ->whereNull('courier_id')
            ->whereNotNull('province_id')
            ->where(function (Builder $query) use ($registeredAt): void {
                $query->whereNull('pickup_deadline_at')
                    ->orWhere('pickup_deadline_at', '>', now());

                // A courier who was not registered when an offer was
                // published must receive the still-unassigned backlog as
                // soon as administration verifies the account. The normal
                // expiry continues to protect couriers who were already
                // eligible when the offer was published.
                if ($registeredAt) {
                    $query->orWhere(function (Builder $historicalOffers) use ($registeredAt): void {
                        $historicalOffers
                            ->whereNotNull('pickup_deadline_at')
                            ->whereNotNull('offer_opened_at')
                            ->where('offer_opened_at', '<=', $registeredAt);
                    });
                }
            });

        if ($branchId) {
            return $orders->where(function (Builder $orderBranches) use ($branchId): void {
                $orderBranches
                    ->where('branch_id', $branchId)
                    ->orWhere('origin_branch_id', $branchId);
            });
        }

        return $orders->whereIn('province_id', $provinceIds);
    }

    public function canClaim(Order $order, User $courier): bool
    {
        if (! $courier->isCourierVerified() || $order->status !== 'pending' || $order->courier_id !== null || ! $order->province_id) {
            return false;
        }

        // `available()` allows an expired offer only when it was published
        // before this courier registered. Re-check that rule after locking
        // the order so an old detail sheet cannot bypass the live policy.
        if ($order->pickup_deadline_at
            && $order->pickup_deadline_at->lessThanOrEqualTo(now())
            && ! $this->wasPublishedBeforeCourierRegistration($order, $courier)) {
            return false;
        }

        $branchId = $this->operatingBranchId($courier);
        if ($branchId) {
            return (int) $order->branch_id === $branchId
                || (int) $order->origin_branch_id === $branchId;
        }

        return $this->canServeProvince($courier, (int) $order->province_id);
    }

    public function canServeProvince(User $courier, int $provinceId): bool
    {
        return in_array($provinceId, $this->provinceIds($courier), true);
    }

    /** @return array<int, int> */
    public function provinceIds(User $courier): array
    {
        return $courier->provinces()
            ->pluck('provinces.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function operatingBranchId(User $courier): ?int
    {
        $branchId = (int) $courier->branch_id;
        if ($branchId <= 0) {
            return null;
        }

        return Branch::withoutGlobalScopes()
            ->whereKey($branchId)
            ->where('is_platform_managed', true)
            ->where('is_active', true)
            ->exists() ? $branchId : null;
    }

    private function wasPublishedBeforeCourierRegistration(Order $order, User $courier): bool
    {
        if (! $courier->created_at) {
            return false;
        }

        // An explicit offer timestamp is required. Legacy pending records
        // without one continue to use the normal expiry safeguard.
        $publishedAt = $order->offer_opened_at;

        return $publishedAt !== null && $publishedAt->lessThanOrEqualTo($courier->created_at);
    }

    protected function base(): Builder
    {
        return Order::withoutGlobalScope(TenantScope::class);
    }
}
