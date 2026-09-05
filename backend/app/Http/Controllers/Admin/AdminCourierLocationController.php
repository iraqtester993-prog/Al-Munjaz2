<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BranchDashboardContext;
use App\Services\BranchDashboardScope;
use App\Services\CourierLocationService;
use App\Services\DashboardBranchFilter;
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
    public function index(
        Request $request,
        CourierLocationService $locations,
        BranchDashboardContext $branchDashboard,
    ): JsonResponse|Response
    {
        /** @var User|null $user */
        $user = $request->user();
        $scope = $branchDashboard->fromRequest($request);

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
            $branchFilter = app(DashboardBranchFilter::class);
            $selectedBranchId = $branchFilter->selectedBranchId($request, $scope);
            if ($scope->requiresBranchScope()) {
                abort_unless($scope->hasBranchScope(), 403);
            }

            return Inertia::render('Admin/CourierLocations', [
                // The existing service intentionally supports multi-branch
                // operations for older portal accounts. A full dashboard
                // branch manager is narrower: use the one server-resolved
                // primary branch directly in SQL, never their complete
                // membership history.
                'couriers' => $scope->hasBranchScope() || ($scope->isSuperAdmin() && $selectedBranchId)
                    ? $this->branchFilteredCourierRows($scope, $selectedBranchId, $branchFilter)
                    : $locations->dashboardCourierRows($user),
                'branchFilter' => $branchFilter->payload($request, $scope),
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

    /**
     * Keep the browser map roster in the same response shape as
     * CourierLocationService::dashboardCourierRows(), but constrain the SQL
     * query to the active branch context before any courier record or
     * coordinate is selected.
     *
     * @return array<int, array{id:int,name:string,phone:string,role:string,is_online:bool,location:array{latitude:float,longitude:float,accuracy_meters:int|null,updated_at:string,address_label:string|null}|null}>
     */
    private function branchFilteredCourierRows(
        BranchDashboardScope $scope,
        ?int $selectedBranchId,
        DashboardBranchFilter $branchFilter,
    ): array
    {
        $couriers = User::withoutGlobalScopes()
            ->where('role', 'courier')
            ->where('status', 'active')
            ->whereNull('deleted_at');

        if ($scope->hasBranchScope()) {
            $scope->restrictUsers($couriers);
        } else {
            $branchFilter->restrictByColumn(
                $couriers,
                $selectedBranchId,
                $couriers->getModel()->qualifyColumn('branch_id'),
            );
        }

        return $couriers
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
            ->map(fn (User $courier) => $this->branchDashboardCourierRow($courier))
            ->values()
            ->all();
    }

    /**
     * @return array{id:int,name:string,phone:string,role:string,is_online:bool,location:array{latitude:float,longitude:float,accuracy_meters:int|null,updated_at:string,address_label:string|null}|null}
     */
    private function branchDashboardCourierRow(User $courier): array
    {
        $location = null;

        if (
            $courier->current_latitude !== null
            && $courier->current_longitude !== null
            && $courier->location_updated_at !== null
            && $courier->location_updated_at->greaterThanOrEqualTo(
                now()->subMinutes(CourierLocationService::OPERATIONAL_FRESHNESS_MINUTES),
            )
        ) {
            $addressLabel = trim((string) $courier->address);
            $location = [
                'latitude' => (float) $courier->current_latitude,
                'longitude' => (float) $courier->current_longitude,
                'accuracy_meters' => $courier->location_accuracy_meters === null
                    ? null
                    : (int) $courier->location_accuracy_meters,
                'updated_at' => $courier->location_updated_at->toIso8601String(),
                'address_label' => $addressLabel === '' ? null : $addressLabel,
            ];
        }

        return [
            'id' => $courier->id,
            'name' => $courier->name,
            'phone' => $courier->phone,
            'role' => $courier->role,
            'is_online' => (bool) $courier->is_online,
            'location' => $location,
        ];
    }
}
