<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CourierLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourierLocationController extends Controller
{
    /**
     * Store the courier's latest consented device position. The endpoint has
     * no client timestamp and never writes a history record.
     */
    public function store(Request $request, CourierLocationService $locations): JsonResponse
    {
        /** @var User|null $courier */
        $courier = $request->user();

        abort_unless(
            $courier?->isCourierRole() && $courier->isActiveUser(),
            403,
            'مشاركة الموقع متاحة للمندوب النشط فقط.',
        );

        $data = $request->validate([
            'latitude' => ['bail', 'required', 'numeric', 'between:-90,90'],
            'longitude' => ['bail', 'required', 'numeric', 'between:-180,180'],
            // Browser geolocation reports a floating-point radius. It is
            // rounded server-side and capped to reject implausible payloads.
            'accuracy_meters' => ['nullable', 'numeric', 'min:0', 'max:50000'],
            // Compatibility with the native GeolocationPosition property
            // name. New clients should prefer `accuracy_meters`.
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:50000'],
        ]);

        $data['accuracy_meters'] = $data['accuracy_meters'] ?? $data['accuracy'] ?? null;

        $courier = $locations->record($courier, $data);

        return response()->json([
            'data' => [
                'latitude' => (float) $courier->current_latitude,
                'longitude' => (float) $courier->current_longitude,
                'accuracy_meters' => $courier->location_accuracy_meters === null
                    ? null
                    : (int) $courier->location_accuracy_meters,
                'updated_at' => $courier->location_updated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Stop sharing immediately. This lets a courier revoke the map pin from
     * their profile without waiting for the normal freshness timeout.
     */
    public function destroy(Request $request, CourierLocationService $locations): JsonResponse
    {
        /** @var User|null $courier */
        $courier = $request->user();

        abort_unless($courier?->isCourierRole(), 403, 'مشاركة الموقع متاحة للمندوب فقط.');

        $locations->clear($courier);

        return response()->json(null, 204);
    }
}
