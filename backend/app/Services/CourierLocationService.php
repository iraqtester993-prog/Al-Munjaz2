<?php

namespace App\Services;

use App\Models\User;

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
     * A live operational pin is useful only for a short time. We keep a
     * single value, and hide it after this window rather than presenting an
     * old position as if the courier were still there.
     */
    private const DASHBOARD_FRESHNESS_MINUTES = 15;

    /**
     * @param array{latitude: numeric, longitude: numeric, accuracy_meters?: numeric|null} $location
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
            ->where('location_updated_at', '>=', now()->subMinutes(self::DASHBOARD_FRESHNESS_MINUTES))
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
}
