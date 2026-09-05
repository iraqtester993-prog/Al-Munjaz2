<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchMembership;
use App\Models\DashboardPermissionProfile;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourierLocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::clear();
        $this->seed(PlanSeeder::class);
        $this->seed(ProvinceSeeder::class);
        $this->seed(DemoSeeder::class);
    }

    public function test_active_courier_replaces_only_their_last_known_position(): void
    {
        $courier = User::where('username', 'مندوب')->firstOrFail();

        $this->actingAs($courier)
            ->postJson('/app/location', [
                'latitude' => 33.3152412,
                'longitude' => 44.3660731,
                // Match the browser GeolocationPosition property name.
                'accuracy' => 14.7,
            ])
            ->assertOk()
            ->assertJsonPath('data.latitude', 33.3152412)
            ->assertJsonPath('data.longitude', 44.3660731)
            ->assertJsonPath('data.accuracy_meters', 15)
            ->assertJsonPath('data.updated_at', fn ($value) => filled($value));

        $courier->refresh();
        $firstUpdatedAt = $courier->location_updated_at;
        $this->assertSame('33.3152412', $courier->current_latitude);
        $this->assertSame('44.3660731', $courier->current_longitude);
        $this->assertSame(15, $courier->location_accuracy_meters);

        $this->travel(1)->seconds();

        $this->actingAs($courier)
            ->postJson('/app/location', [
                'latitude' => 33.3181000,
                'longitude' => 44.3500000,
            ])
            ->assertOk();

        $courier->refresh();
        $this->assertSame('33.3181000', $courier->current_latitude);
        $this->assertSame('44.3500000', $courier->current_longitude);
        $this->assertNull($courier->location_accuracy_meters);
        $this->assertTrue($courier->location_updated_at->greaterThan($firstUpdatedAt));
    }

    public function test_location_endpoint_rejects_non_couriers_inactive_accounts_and_invalid_coordinates(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();

        $this->actingAs($merchant)
            ->postJson('/app/location', ['latitude' => 33.3, 'longitude' => 44.3])
            ->assertForbidden();

        $this->actingAs($courier)
            ->postJson('/app/location', ['latitude' => 91, 'longitude' => 44.3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('latitude');

        $courier->update(['status' => 'suspended']);
        Sanctum::actingAs($courier);
        $this->postJson('/api/v1/courier/location', ['latitude' => 33.3, 'longitude' => 44.3])
            ->assertForbidden();
    }

    public function test_admin_receives_last_known_courier_map_pins_and_merchant_pickup_location_travels_with_an_order(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();

        $courier->update([
            'current_latitude' => 33.3123456,
            'current_longitude' => 44.3987654,
            'location_accuracy_meters' => 12,
            'location_updated_at' => now(),
        ]);

        // The overview intentionally stays lightweight. Current courier
        // coordinates are loaded only by the dedicated locations screen,
        // which prevents a potentially large map payload from delaying the
        // dashboard's first render.
        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard')
                ->missing('courierLocations'));

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/couriers/locations')
            ->assertOk()
            ->assertJsonPath('meta.kind', 'last_known_positions')
            ->assertJsonPath('data.0.id', $courier->id)
            ->assertJsonPath('data.0.latitude', 33.3123456)
            ->assertJsonMissing(['current_latitude' => '33.3123456']);

        Sanctum::actingAs($merchant);
        $this->getJson('/api/v1/admin/couriers/locations')->assertForbidden();

        $pickupPayload = [
            'customer_name_ar' => 'عميل موقع الاستلام',
            'phone' => '07710000999',
            'address_ar' => 'بغداد — الكرادة',
            'delivery_vehicle' => 'bike',
            'province_id' => $province->id,
            'price' => 25000,
            'pickup_latitude' => 33.3200111,
            'pickup_longitude' => 44.4100222,
        ];

        // A coordinate is not useful to a courier without a clear merchant
        // label, so the API enforces the full pickup-location tuple.
        $this->postJson('/api/v1/orders', $pickupPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pickup_location_label');

        $missingPickupPayload = $pickupPayload;
        unset(
            $missingPickupPayload['pickup_latitude'],
            $missingPickupPayload['pickup_longitude'],
        );

        $this->postJson('/api/v1/orders', $missingPickupPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'pickup_latitude',
                'pickup_longitude',
                'pickup_location_label',
            ]);

        $response = $this->postJson('/api/v1/orders', $pickupPayload + [
            'pickup_location_label' => 'متجر التاجر — الكرادة',
        ])
            ->assertCreated()
            ->assertJsonPath('data.pickup_latitude', 33.3200111)
            ->assertJsonPath('data.pickup_longitude', 44.4100222)
            ->assertJsonPath('data.pickup_location_label', 'متجر التاجر — الكرادة');

        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $this->assertSame('33.3200111', $order->pickup_latitude);
        $this->assertSame('44.4100222', $order->pickup_longitude);
        $this->assertSame('متجر التاجر — الكرادة', $order->pickup_location_label);
    }

    public function test_dashboard_location_page_lists_active_couriers_and_only_exposes_a_fresh_selected_position(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $courier->update([
            'address' => 'بغداد — الكرادة — شارع 62',
            'current_latitude' => 33.3152412,
            'current_longitude' => 44.3660731,
            'location_accuracy_meters' => 18,
            'location_updated_at' => now(),
        ]);

        $withoutLocation = User::create([
            'tenant_id' => $courier->tenant_id,
            'name' => 'مندوب بلا موقع',
            'username' => 'courier-without-location',
            'email' => 'courier-without-location@example.test',
            'phone' => '07720000009',
            'password' => 'Password123!',
            'role' => 'courier',
            'status' => 'active',
            'is_online' => false,
        ]);

        $hiddenInactiveCourier = User::create([
            'tenant_id' => $courier->tenant_id,
            'name' => 'مندوب موقوف',
            'username' => 'suspended-courier-location',
            'email' => 'suspended-courier-location@example.test',
            'phone' => '07720000010',
            'password' => 'Password123!',
            'role' => 'delivery_courier',
            'status' => 'suspended',
            'current_latitude' => 33.3200000,
            'current_longitude' => 44.3700000,
            'location_updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/dashboard/couriers/locations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/CourierLocations')
                ->has('couriers', 2)
                ->where('couriers.0.id', $courier->id)
                ->where('couriers.0.location.latitude', 33.3152412)
                ->where('couriers.0.location.longitude', 44.3660731)
                ->where('couriers.0.location.accuracy_meters', 18)
                ->where('couriers.0.location.address_label', 'بغداد — الكرادة — شارع 62')
                ->where('couriers.1.id', $withoutLocation->id)
                ->where('couriers.1.location', null));

        $this->assertDatabaseHas('users', [
            'id' => $hiddenInactiveCourier->id,
            'current_latitude' => '33.3200000',
        ]);
    }

    public function test_branch_dashboard_accounts_cannot_open_the_platform_wide_courier_locations_page(): void
    {
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $branchOwner = User::create([
            'tenant_id' => $courier->tenant_id,
            'name' => 'مالك فرع المواقع',
            'username' => 'branch-owner-locations',
            'email' => 'branch-owner-locations@example.test',
            'phone' => '07720000011',
            'password' => 'Password123!',
            'role' => 'owner',
            'status' => 'active',
        ]);

        $response = $this->actingAs($branchOwner)->get('/dashboard/couriers/locations');

        $response->assertRedirect();
        $this->assertStringEndsWith('/dashboard/branch', (string) $response->headers->get('Location'));
    }

    public function test_delegated_dashboard_operator_only_receives_couriers_in_its_tenant_or_explicit_branches(): void
    {
        $platform = Tenant::platform();
        $courierTenant = User::where('username', 'مندوب')->value('tenant_id');
        $profile = DashboardPermissionProfile::create([
            'name' => 'مشاهدة مواقع مقيدة',
            'permissions' => ['courier_locations' => ['view']],
        ]);
        $operator = User::create([
            'tenant_id' => $platform->id,
            'name' => 'مشغل مواقع مقيد',
            'username' => 'scoped-location-operator',
            'email' => 'scoped-location-operator@example.test',
            'phone' => '07980000111',
            'password' => 'Password123!',
            'role' => 'admin',
            'status' => 'active',
            'permission_profile_id' => $profile->id,
        ]);
        $allowedBranch = Branch::withoutGlobalScopes()->create([
            'tenant_id' => $platform->id,
            'code' => 'LOC-SCOPE',
            'name_ar' => 'فرع مواقع مقيد',
            'is_platform_managed' => true,
            'is_active' => true,
        ]);
        $operator->managedBranches()->attach($allowedBranch->id, [
            'access_role' => BranchMembership::MANAGER,
        ]);

        $ownTenantCourier = $this->locationCourier([
            'tenant_id' => $platform->id,
            'name' => 'مندوب نفس المستأجر',
            'username' => 'same-tenant-location-courier',
            'email' => 'same-tenant-location-courier@example.test',
            'phone' => '07720000012',
        ]);
        $allowedBranchCourier = $this->locationCourier([
            'tenant_id' => $courierTenant,
            'branch_id' => $allowedBranch->id,
            'name' => 'مندوب فرع مسموح',
            'username' => 'allowed-branch-location-courier',
            'email' => 'allowed-branch-location-courier@example.test',
            'phone' => '07720000013',
        ]);
        $foreignCourier = $this->locationCourier([
            'tenant_id' => $courierTenant,
            'name' => 'مندوب مستأجر آخر',
            'username' => 'foreign-location-courier',
            'email' => 'foreign-location-courier@example.test',
            'phone' => '07720000014',
        ]);

        $response = $this->actingAs($operator)->get('/dashboard/couriers/locations');

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/CourierLocations')
                ->has('couriers', 2));

        $visibleIds = collect($response->inertiaPage()['props']['couriers'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains($ownTenantCourier->id, $visibleIds);
        $this->assertContains($allowedBranchCourier->id, $visibleIds);
        $this->assertNotContains($foreignCourier->id, $visibleIds);
    }

    /** @param array<string, mixed> $attributes */
    private function locationCourier(array $attributes): User
    {
        return User::create($attributes + [
            'password' => 'Password123!',
            'role' => 'courier',
            'status' => 'active',
            'is_online' => true,
            'current_latitude' => 33.3152412,
            'current_longitude' => 44.3660731,
            'location_updated_at' => now(),
        ]);
    }

    public function test_courier_can_clear_their_shared_point_and_the_dashboard_hides_stale_points(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();

        $courier->update([
            'current_latitude' => 33.3152412,
            'current_longitude' => 44.3660731,
            'location_updated_at' => now(),
        ]);

        $this->actingAs($courier)->deleteJson('/app/location')->assertNoContent();
        $courier->refresh();
        $this->assertNull($courier->current_latitude);
        $this->assertNull($courier->current_longitude);
        $this->assertNull($courier->location_updated_at);

        $courier->update([
            'current_latitude' => 33.3152412,
            'current_longitude' => 44.3660731,
            'location_updated_at' => now()->subMinutes(16),
        ]);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard')
                ->missing('courierLocations'));
    }
}
