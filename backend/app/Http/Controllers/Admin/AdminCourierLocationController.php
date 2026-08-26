<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CourierLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCourierLocationController extends Controller
{
    /**
     * Map-ready last-known courier locations for the operations dashboard.
     * It is intentionally an administrator-only endpoint and never emits a
     * coordinate history or device-identifying metadata.
     */
    public function index(Request $request, CourierLocationService $locations): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        abort_unless($user?->isAdmin() && $user->isActiveUser(), 403);

        return response()->json([
            'data' => $locations->dashboardRows(),
            'meta' => [
                'kind' => 'last_known_positions',
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
