<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CourierLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminCourierLocationController extends Controller
{
    /**
     * Map-ready last-known courier locations for the operations dashboard.
     * It is intentionally an administrator-only endpoint and never emits a
     * coordinate history or device-identifying metadata.
     */
    public function index(Request $request, CourierLocationService $locations): JsonResponse|Response
    {
        /** @var User|null $user */
        $user = $request->user();

        // The browser dashboard has a dedicated, profile-aware route while
        // the mobile API remains super-admin-only until API parity exists.
        $allowed = $request->is('api/*')
            ? $user?->isSuperAdmin()
            : $user?->canUseAdminPermission('courier_locations', 'view');

        abort_unless($user instanceof User && $allowed && $user->isActiveUser(), 403);

        // The mobile API retains its compact map feed for compatible native
        // clients. The browser route is a full dashboard surface: it must
        // include couriers without a fresh position too, so selecting one can
        // clearly explain that no current shared location is available.
        if (! $request->is('api/*')) {
            return Inertia::render('Admin/CourierLocations', [
                'couriers' => $locations->dashboardCourierRows($user),
            ]);
        }

        return response()->json([
            'data' => $locations->dashboardRows(),
            'meta' => [
                'kind' => 'last_known_positions',
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
