<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Current-position storage for operations.
 *
 * This service deliberately keeps exactly one position on the user record.
 * It is not a route tracker and must never be changed into a history writer
 * without an explicit product, consent, and retention decision.
 */
class CourierLocationService
{
    /**
     * A live operational pin is useful only for a short time. Fifteen minutes
     * keeps it useful without making a courier who briefly loses reception
     * re-authorize every action. The timestamp is set by the server only.
     */
    public const OPERATIONAL_FRESHNESS_MINUTES = 15;

    public const OPERATIONAL_LOCATION_REQUIRED_MESSAGE = 'لا يمكن متابعة الطلب قبل تفعيل الموقع. اسمح للتطبيق بتحديث موقعك، وتأكد أن آخر تحديث خلال 15 دقيقة.';

    /**
     * @param  array{latitude: numeric, longitude: numeric, accuracy_meters?: numeric|null}  $location
     */
    public function record(User $courier, array $location): User
    {
        $courier->forceFill([
            'current_latitude' => round((float) $location['latitude'], 7),
            'current_longitude' => round((float) $location['longitude'], 7),
            'location_accuracy_meters' => array_key_exists('accuracy_meters', $location) && $location['accuracy_meters'] !== null
                ? (int) round((float) $location['accuracy_meters'])
                : null,
            // Use the server clock only. Client timestamps can be stale or
            // fabricated and would make the map's last-known indicator lie.
            'location_updated_at' => now(),
            'last_active_at' => now(),
        ])->save();

        return $courier->fresh();
    }

    /**
     * Remove a courier's shared point as soon as they stop sharing from
     * their account. This is not a deletion of historical data because the
     * application does not retain a location history in the first place.
     */
    public function clear(User $courier): void
    {
        $courier->forceFill([
            'current_latitude' => null,
            'current_longitude' => null,
            'location_accuracy_meters' => null,
            'location_updated_at' => null,
        ])->save();
    }

    /**
     * A position is operationally trustworthy only when both coordinates and
     * a recent server-side timestamp are present. Accuracy remains optional
     * because browsers are permitted to omit it, while coordinates and the
     * server timestamp cannot be forged by an ordinary client request.
     */
    public function hasFreshOperationalLocation(User $courier): bool
    {
        return $courier->current_latitude !== null
            && $courier->current_longitude !== null
            && $courier->location_updated_at !== null
            && $courier->location_updated_at->greaterThanOrEqualTo(
                now()->subMinutes(self::OPERATIONAL_FRESHNESS_MINUTES),
            );
    }

    /**
     * Guard courier-only order operations. Using a ValidationException gives
     * API clients a 422 JSON response and lets the PWA render the same clear
     * field error through its normal Inertia form flow.
     */
    public function requireFreshOperationalLocation(User $courier): void
    {
        if (! $this->hasFreshOperationalLocation($courier)) {
            throw ValidationException::withMessages([
                'location' => [self::OPERATIONAL_LOCATION_REQUIRED_MESSAGE],
            ]);
        }
    }

    /**
     * Dashboard map pins. No location trail, device metadata, or raw account
     * record is exposed from this method.
     *
     * @return array<int, array{id:int,name:string,phone:string,role:string,latitude:float,longitude:float,accuracy_meters:int|null,updated_at:string,is_online:bool}>
     */
    public function dashboardRows(): array
    {
        return User::withoutGlobalScopes()
            ->whereIn('role', User::COURIER_ROLES)
            ->where('status', 'active')
            // `withoutGlobalScopes()` is deliberate for cross-tenant
            // operations, but it also removes SoftDeletes' scope. A removed
            // account must never remain visible as a live map pin.
            ->whereNull('deleted_at')
            ->whereNotNull('current_latitude')
            ->whereNotNull('current_longitude')
            ->where('location_updated_at', '>=', now()->subMinutes(self::OPERATIONAL_FRESHNESS_MINUTES))
            ->orderByDesc('location_updated_at')
            ->get([
                'id',
                'name',
                'phone',
                'role',
                'is_online',
                'current_latitude',
                'current_longitude',
                'location_accuracy_meters',
                'location_updated_at',
            ])
            ->map(fn (User $courier) => $this->dashboardRow($courier))
            ->values()
            ->all();
    }

    /**
     * Selection rows for the dedicated dashboard page. Unlike the compact
     * map feed above, this includes every active courier visible to the
     * signed-in dashboard actor even when they have not shared a current
     * position. The page can then keep the roster truthful instead of
     * silently making an active courier disappear.
     *
     * Location data still obeys the same freshness window as dashboardRows;
     * an old coordinate is not presented as a current address.
     *
     * @return array<int, array{id:int,name:string,phone:string,role:string,is_online:bool,location:array{latitude:float,longitude:float,accuracy_meters:int|null,updated_at:string,address_label:string|null}|null}>
     */
    public function dashboardCourierRows(User $actor): array
    {
        $couriers = User::withoutGlobalScopes()
            ->whereIn('role', User::COURIER_ROLES)
            // A suspended, pending, or removed account must never expose a
            // previously shared point through the operations dashboard.
            ->where('status', 'active');

        $this->scopeDashboardCouriersToActor($couriers, $actor);

        return $couriers
            ->whereNull('deleted_at')
            ->orderByDesc('is_online')
            ->orderByDesc('location_updated_at')
            ->orderBy('name')
            ->orderBy('id')
            ->get([
                'id',
                'name',
                'phone',
                'role',
                'address',
                'is_online',
                'current_latitude',
                'current_longitude',
                'location_accuracy_meters',
                'location_updated_at',
            ])
            ->map(fn (User $courier) => $this->dashboardCourierRow($courier))
            ->values()
            ->all();
    }

    /**
     * The dedicated page is available to delegated dashboard operators, so
     * it cannot reuse the super-admin's cross-tenant map query. A delegated
     * operator may see couriers in their own tenant and, separately, those
     * attached to an active branch for which they hold an explicit
     * membership. If neither boundary exists, the safe result is empty.
     *
     * @param  Builder<User>  $couriers
     */
    private function scopeDashboardCouriersToActor(Builder $couriers, User $actor): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        $hasTenantScope = $actor->tenant_id !== null;
        $branchIds = $actor->managedBranches()
            ->where('branches.is_active', true)
            ->pluck('branches.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $couriers->where(function (Builder $visible) use ($actor, $branchIds, $hasTenantScope): void {
            if ($hasTenantScope) {
                $visible->where('tenant_id', $actor->tenant_id);
            }

            if ($branchIds !== []) {
                if ($hasTenantScope) {
                    $visible->orWhereIn('branch_id', $branchIds);
                } else {
                    $visible->whereIn('branch_id', $branchIds);
                }
            }

            if (! $hasTenantScope && $branchIds === []) {
                $visible->whereRaw('0 = 1');
            }
        });
    }

    /**
     * @return array{id:int,name:string,phone:string,role:string,latitude:float,longitude:float,accuracy_meters:int|null,updated_at:string,is_online:bool}
     */
    public function dashboardRow(User $courier): array
    {
        return [
            'id' => $courier->id,
            'name' => $courier->name,
            'phone' => $courier->phone,
            'role' => $courier->role,
            'latitude' => (float) $courier->current_latitude,
            'longitude' => (float) $courier->current_longitude,
            'accuracy_meters' => $courier->location_accuracy_meters === null
                ? null
                : (int) $courier->location_accuracy_meters,
            'updated_at' => $courier->location_updated_at?->toIso8601String(),
            'is_online' => (bool) $courier->is_online,
        ];
    }

    /**
     * @return array{id:int,name:string,phone:string,role:string,is_online:bool,location:array{latitude:float,longitude:float,accuracy_meters:int|null,updated_at:string,address_label:string|null}|null}
     */
    private function dashboardCourierRow(User $courier): array
    {
        return [
            'id' => $courier->id,
            'name' => $courier->name,
            'phone' => $courier->phone,
            'role' => $courier->role,
            'is_online' => (bool) $courier->is_online,
            'location' => $this->freshLocationForDashboard($courier),
        ];
    }

    /**
     * The account address is an optional human-readable label supplied in
     * the courier profile. It is deliberately not reverse-geocoded from the
     * coordinate, which would introduce a third-party disclosure of a
     * courier's live location.
     *
     * @return array{latitude:float,longitude:float,accuracy_meters:int|null,updated_at:string,address_label:string|null}|null
     */
    private function freshLocationForDashboard(User $courier): ?array
    {
        if (
            $courier->current_latitude === null
            || $courier->current_longitude === null
            || $courier->location_updated_at === null
            || $courier->location_updated_at->lessThan(now()->subMinutes(self::OPERATIONAL_FRESHNESS_MINUTES))
        ) {
            return null;
        }

        $addressLabel = trim((string) $courier->address);

        return [
            'latitude' => (float) $courier->current_latitude,
            'longitude' => (float) $courier->current_longitude,
            'accuracy_meters' => $courier->location_accuracy_meters === null
                ? null
                : (int) $courier->location_accuracy_meters,
            'updated_at' => $courier->location_updated_at->toIso8601String(),
            'address_label' => $addressLabel === '' ? null : $addressLabel,
        ];
    }
}
