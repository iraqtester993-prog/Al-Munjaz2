<?php

namespace App\Services;

use App\Models\PricingRule;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;

class PricingService
{
    /** @return array{fee: int, return_fee: int, rule: ?PricingRule} */
    public function quote(User $merchant, int $destinationProvinceId, ?string $service, ?string $vehicle, int $weightGrams, int $fallbackFee = 0): array
    {
        // Pricing can be constrained by both legs of a route.  The merchant's
        // primary enabled province is the origin for an order created from
        // their account; falling back to the first enabled province keeps
        // existing accounts deterministic until they choose a primary one.
        $originProvinceId = $merchant->provinces()
            ->wherePivot('is_primary', true)
            ->value('provinces.id')
            ?: $merchant->provinces()->value('provinces.id');

        $rule = PricingRule::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', Tenant::platform()->id)
            ->where('is_active', true)
            ->where(function ($query) use ($merchant): void {
                $query->whereNull('merchant_id')->orWhere('merchant_id', $merchant->id);
            })
            ->where(function ($query) use ($destinationProvinceId): void {
                $query->whereNull('destination_province_id')->orWhere('destination_province_id', $destinationProvinceId);
            })
            ->where(function ($query) use ($originProvinceId): void {
                $query->whereNull('origin_province_id')->orWhere('origin_province_id', $originProvinceId);
            })
            ->where(function ($query) use ($service): void {
                $query->whereNull('service')->orWhere('service', $service);
            })
            ->where(function ($query) use ($vehicle): void {
                $query->whereNull('vehicle')->orWhere('vehicle', $vehicle);
            })
            ->where('min_weight_grams', '<=', max(0, $weightGrams))
            ->where(function ($query) use ($weightGrams): void {
                $query->whereNull('max_weight_grams')->orWhere('max_weight_grams', '>=', max(0, $weightGrams));
            })
            ->orderByRaw('merchant_id IS NULL')
            ->orderByRaw('origin_province_id IS NULL')
            ->orderByRaw('destination_province_id IS NULL')
            ->orderByRaw('service IS NULL')
            ->orderByRaw('vehicle IS NULL')
            ->orderBy('priority')
            ->orderByDesc('id')
            ->first();

        return [
            'fee' => $rule ? (int) $rule->base_fee : max(0, $fallbackFee),
            'return_fee' => $rule ? (int) $rule->return_fee : 0,
            'rule' => $rule,
        ];
    }
}
