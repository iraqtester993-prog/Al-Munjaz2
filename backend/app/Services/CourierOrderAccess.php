<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Branch;
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
        return match ($courier->role) {
            // A general courier can deliberately be assigned to either leg
            // from the dashboard, so show every explicit assignment.
            'courier' => $this->base()->where(function (Builder $query) use ($courier): void {
                $query->where('courier_id', $courier->id)
                    ->orWhere('pickup_courier_id', $courier->id)
                    ->orWhere('delivery_courier_id', $courier->id);
            }),
            'pickup_courier' => $this->base()->where('pickup_courier_id', $courier->id),
            'delivery_courier' => $this->base()->where('delivery_courier_id', $courier->id),
            // A transporter works from the inter-branch transfer console;
            // it must never see an arbitrary direct-order queue.
            default => $this->base()->whereRaw('1 = 0'),
        };
    }

    public function available(User $courier): Builder
    {
        if ($courier->role !== 'courier') {
            return $this->base()->whereRaw('1 = 0');
        }

        $provinceIds = $this->provinceIds($courier);
        $branchId = $this->operatingBranchId($courier);

        $orders = $this->base()
            ->where('status', 'pending')
            ->whereNull('courier_id')
            ->whereNotNull('province_id')
            ->where(function (Builder $query): void {
                $query->whereNull('pickup_deadline_at')
                    ->orWhere('pickup_deadline_at', '>', now());
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
        if ($courier->role !== 'courier' || $order->status !== 'pending' || $order->courier_id !== null || ! $order->province_id) {
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

    protected function base(): Builder
    {
        return Order::withoutGlobalScope(TenantScope::class);
    }
}
