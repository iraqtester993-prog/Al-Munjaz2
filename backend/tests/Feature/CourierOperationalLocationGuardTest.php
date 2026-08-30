<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Province;
use App\Models\User;
use App\Services\CourierLocationService;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourierOperationalLocationGuardTest extends TestCase
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

    public function test_pwa_courier_cannot_claim_without_a_current_location_and_can_continue_after_a_fresh_update(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $province = $merchant->provinces()->active()->firstOrFail();
        $order = $this->pendingOrder($merchant, $province, 'ALM-PWA-LOCATION-GUARD');

        $courier->update([
            'current_latitude' => null,
            'current_longitude' => null,
            'location_updated_at' => null,
        ]);

        $this->actingAs($courier)
            ->postJson(route('app.orders.claim', $order))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('location')
            ->assertJsonPath('errors.location.0', CourierLocationService::OPERATIONAL_LOCATION_REQUIRED_MESSAGE);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending',
            'courier_id' => null,
        ]);

        $courier->update([
            'current_latitude' => 33.3152412,
            'current_longitude' => 44.3660731,
            'location_updated_at' => now()->subMinutes(CourierLocationService::OPERATIONAL_FRESHNESS_MINUTES + 1),
        ]);

        $this->actingAs($courier)
            ->postJson(route('app.orders.claim', $order))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('location')
            ->assertJsonPath('errors.location.0', CourierLocationService::OPERATIONAL_LOCATION_REQUIRED_MESSAGE);

        $courier->update([
            'location_updated_at' => now(),
        ]);

        $this->actingAs($courier)
            ->post(route('app.orders.claim', $order))
            ->assertRedirect();

        $order->refresh();
        $this->assertSame('approved', $order->status);
        $this->assertSame($courier->id, $order->courier_id);

        // A freshly shared location also permits the courier's next status
        // transition; the guard is not limited to claiming a job.
        $this->actingAs($courier)
            ->post(route('app.orders.status', $order), ['status' => 'courier'])
            ->assertRedirect();

        $this->assertSame('courier', $order->fresh()->status);
    }

    public function test_api_rejects_missing_or_stale_courier_locations_but_keeps_merchant_creation_unchanged(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $province = $merchant->provinces()->active()->firstOrFail();
        $order = $this->assignedOrder($merchant, $province, $courier, 'ALM-API-LOCATION-GUARD');

        $courier->update([
            'current_latitude' => null,
            'current_longitude' => null,
            'location_updated_at' => null,
        ]);
        Sanctum::actingAs($courier);

        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'courier'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('location')
            ->assertJsonPath('errors.location.0', CourierLocationService::OPERATIONAL_LOCATION_REQUIRED_MESSAGE);

        $courier->update([
            'current_latitude' => 33.3152412,
            'current_longitude' => 44.3660731,
            'location_updated_at' => now()->subMinutes(CourierLocationService::OPERATIONAL_FRESHNESS_MINUTES + 1),
        ]);

        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'courier'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('location');

        $courier->update(['location_updated_at' => now()]);

        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'courier'])
            ->assertOk()
            ->assertJsonPath('data.status', 'courier');

        // Merchant order creation uses its order pickup point and must not be
        // blocked by the courier's own live-location requirement.
        Sanctum::actingAs($merchant);
        $this->postJson('/api/v1/orders', [
            'customer_name_ar' => 'عميل التاجر بلا موقع جهاز',
            'phone' => '07710000888',
            'address_ar' => 'بغداد — الكرادة',
            'pickup_latitude' => 33.3152412,
            'pickup_longitude' => 44.3660731,
            'pickup_location_label' => 'متجر التاجر — الكرادة',
            'delivery_vehicle' => 'normal',
            'province_id' => $province->id,
            'price' => 25000,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');
    }

    private function pendingOrder(User $merchant, Province $province, string $track): Order
    {
        return Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => $track,
            'source' => 'merchant',
            'customer_name_ar' => 'عميل موقع التشغيل',
            'customer_name_en' => 'Operational location customer',
            'phone' => '07710000777',
            'address_ar' => 'بغداد — المنصور',
            'address_en' => 'Baghdad — Mansour',
            'delivery_vehicle' => 'normal',
            'price' => 25000,
            'fee' => 2500,
            'status' => 'pending',
            'workflow_stage' => 'created',
            'province_id' => $province->id,
            'pickup_deadline_at' => now()->addMinutes(30),
            'date' => today(),
        ]);
    }

    private function assignedOrder(User $merchant, Province $province, User $courier, string $track): Order
    {
        return Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => $track,
            'source' => 'merchant',
            'customer_name_ar' => 'عميل API موقع التشغيل',
            'customer_name_en' => 'API operational location customer',
            'phone' => '07710000666',
            'address_ar' => 'بغداد — المنصور',
            'address_en' => 'Baghdad — Mansour',
            'delivery_vehicle' => 'normal',
            'price' => 25000,
            'fee' => 2500,
            'status' => 'approved',
            'workflow_stage' => 'awaiting_pickup',
            'courier_id' => $courier->id,
            'province_id' => $province->id,
            'date' => today(),
        ]);
    }
}
