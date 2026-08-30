<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Province;
use App\Models\Setting;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiOrderSecurityTest extends TestCase
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

    public function test_support_cannot_use_the_administrator_api_surface(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $support = User::create([
            'tenant_id' => $merchant->tenant_id,
            'name' => 'موظف الدعم',
            'username' => 'api-support',
            'phone' => '07981110000',
            'password' => 'password',
            'role' => 'support',
            'status' => 'active',
        ]);

        Sanctum::actingAs($support);

        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/admin/couriers')->assertForbidden();
        $this->putJson('/api/v1/admin/settings', ['delivery_fee' => 1])->assertForbidden();
        $this->getJson('/api/v1/admin/reports/finance')->assertForbidden();
    }

    public function test_merchant_api_order_creation_uses_server_pricing_and_province_entitlement(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $enabledProvince = $merchant->provinces()->firstOrFail();
        $disabledProvince = Province::query()->whereKeyNot($enabledProvince->id)->firstOrFail();
        Setting::set('delivery_fee', 3200);

        Sanctum::actingAs($merchant);

        $payload = [
            'customer_name_ar' => 'عميل واجهة API',
            'phone' => '07710000090',
            'phone2' => '07710000091',
            'address_ar' => 'بغداد — الكرادة',
            'pickup_latitude' => 33.3152412,
            'pickup_longitude' => 44.3660731,
            'pickup_location_label' => 'متجر واجهة API — الكرادة',
            'order_type' => 'منتج',
            'delivery_vehicle' => 'bike',
            'weight_grams' => 900,
            'vehicle_note' => 'حقيبة حرارية',
            'price' => 50000,
            // This must be ignored in favour of the dashboard's pricing rule.
            'fee' => 1,
            'return_fee' => 1,
        ];

        $this->postJson('/api/v1/orders', $payload + ['province_id' => $disabledProvince->id])
            ->assertUnprocessable();

        $response = $this->postJson('/api/v1/orders', $payload + ['province_id' => $enabledProvince->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.workflow_stage', 'created')
            ->assertJsonPath('data.fee', 3200)
            ->assertJsonPath('data.return_fee', 0)
            ->assertJsonPath('data.delivery_vehicle', 'bike')
            ->assertJsonPath('data.weight_grams', 900)
            ->assertJsonPath('data.customer_name_en', 'عميل واجهة API')
            ->assertJsonPath('data.address_en', 'بغداد — الكرادة');

        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $this->assertSame($merchant->tenant_id, $order->tenant_id);
        $this->assertSame($merchant->id, $order->merchant_id);
        $this->assertSame(3200, (int) $order->fee);
        $this->assertSame(0, (int) $order->return_fee);
        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $order->id,
            'to_status' => 'pending',
            'user_id' => $merchant->id,
        ]);
    }

    public function test_courier_api_cannot_read_unassigned_orders_or_skip_workflow_stages(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();

        $assigned = $this->makeOrder($merchant, $province, $courier, 'ALM-API-ASSIGNED', 'approved');
        $otherCourier = User::create([
            'tenant_id' => $courier->tenant_id,
            'name' => 'مندوب آخر',
            'username' => 'api-other-courier',
            'phone' => '07981110001',
            'password' => 'password',
            'role' => 'courier',
            'status' => 'active',
        ]);
        $otherCourier->provinces()->syncWithoutDetaching([$province->id => ['is_primary' => true]]);
        $notAssigned = $this->makeOrder($merchant, $province, $otherCourier, 'ALM-API-OTHER', 'approved');

        $courier->update([
            'current_latitude' => 33.3152412,
            'current_longitude' => 44.3660731,
            'location_updated_at' => now(),
        ]);

        Sanctum::actingAs($courier);

        $this->getJson("/api/v1/orders/{$notAssigned->id}")->assertForbidden();
        $this->patchJson("/api/v1/orders/{$assigned->id}/status", ['status' => 'delivered'])
            ->assertUnprocessable();
        $this->patchJson("/api/v1/orders/{$assigned->id}/status", ['status' => 'courier'])
            ->assertOk()
            ->assertJsonPath('data.status', 'courier');
        $this->patchJson("/api/v1/orders/{$assigned->id}/status", ['status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');

        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $assigned->id,
            'from_status' => 'approved',
            'to_status' => 'courier',
            'user_id' => $courier->id,
        ]);
        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $assigned->id,
            'from_status' => 'courier',
            'to_status' => 'delivered',
            'user_id' => $courier->id,
        ]);
    }

    public function test_courier_api_return_uses_the_same_two_step_handback_as_the_pwa(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $order = $this->makeOrder($merchant, $province, $courier, 'ALM-API-RETURN', 'courier');
        $order->update(['workflow_stage' => 'out_for_delivery', 'return_fee' => 3500]);
        $courier->update([
            'current_latitude' => 33.3152412,
            'current_longitude' => 44.3660731,
            'location_updated_at' => now(),
        ]);

        Sanctum::actingAs($courier);

        $this->postJson("/api/v1/orders/{$order->id}/return", [
            'fee_mode' => 'fee',
            'return_fee_applied' => 2000,
            'note' => 'تعذر الوصول إلى العميل.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'returned')
            ->assertJsonPath('data.workflow_stage', 'return_pending_merchant')
            ->assertJsonPath('data.return_fee_applied', 2000);

        $this->postJson("/api/v1/orders/{$order->id}/return-to-merchant", [
            'note' => 'تم التسليم للتاجر.',
        ])
            ->assertOk()
            ->assertJsonPath('data.workflow_stage', 'returned_to_merchant');

        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'user_id' => $courier->id,
            'type' => 'delivery_fee',
            'amount' => 2000,
            'direction' => -1,
        ]);
    }

    private function makeOrder(User $merchant, Province $province, User $courier, string $track, string $status): Order
    {
        return Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => $track,
            'source' => 'merchant',
            'customer_name_ar' => 'عميل API',
            'customer_name_en' => 'API customer',
            'phone' => '078'.str_pad((string) random_int(1, 9_999_999), 7, '0', STR_PAD_LEFT),
            'address_ar' => 'بغداد — المنصور',
            'address_en' => 'Baghdad — Mansour',
            'delivery_vehicle' => 'normal',
            'price' => 25000,
            'fee' => 2500,
            'status' => $status,
            'workflow_stage' => 'awaiting_pickup',
            'courier_id' => $courier->id,
            'province_id' => $province->id,
            'date' => today(),
        ]);
    }
}
