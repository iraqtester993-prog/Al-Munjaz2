<?php

namespace Tests\Feature;

use App\Models\Order;
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

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard')
                ->has('courierLocations', 1)
                ->where('courierLocations.0.id', $courier->id)
                ->where('courierLocations.0.latitude', 33.3123456)
                ->where('courierLocations.0.longitude', 44.3987654)
                ->where('courierLocations.0.accuracy_meters', 12)
                ->where('courierLocations.0.is_online', (bool) $courier->is_online));

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
                ->has('courierLocations', 0));
    }
}
