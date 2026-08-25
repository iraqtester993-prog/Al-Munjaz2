<?php

namespace App\Services;

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
        return $this->base()
            ->where('courier_id', $courier->id);
    }

    public function available(User $courier): Builder
    {
        $provinceIds = $this->provinceIds($courier);

        return $this->base()
            ->where('status', 'pending')
            ->whereNull('courier_id')
            ->whereNotNull('province_id')
            ->whereIn('province_id', $provinceIds)
            ->where(function (Builder $query): void {
                $query->whereNull('pickup_deadline_at')
                    ->orWhere('pickup_deadline_at', '>', now());
            });
    }

    public function canClaim(Order $order, User $courier): bool
    {
        if ($order->status !== 'pending' || $order->courier_id !== null || ! $order->province_id) {
            return false;
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

    protected function base(): Builder
    {
        return Order::withoutGlobalScope(TenantScope::class);
    }
}
