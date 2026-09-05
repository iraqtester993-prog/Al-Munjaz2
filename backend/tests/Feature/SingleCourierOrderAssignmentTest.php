<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SingleCourierOrderAssignmentTest extends TestCase
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

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    public function test_direct_orders_can_only_be_assigned_to_one_general_courier(): void
    {
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $merchant = User::query()->where('username', 'تاجر')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $specialist = User::withoutGlobalScopes()->create([
            'tenant_id' => User::query()->where('username', 'مندوب')->value('tenant_id'),
            'name' => 'مندوب استلام قديم',
            'username' => 'legacy-pickup-'.random_int(1000, 9999),
            'phone' => '079'.str_pad((string) random_int(1, 9_999_999), 7, '0', STR_PAD_LEFT),
            'password' => 'password',
            'role' => 'pickup_courier',
            'status' => 'active',
            'vehicle' => 'bike',
        ]);
        $specialist->provinces()->syncWithoutDetaching([$province->id => ['is_primary' => true]]);
        $order = $this->pendingOrder($merchant, $province);

        $this->actingAs($admin)
            ->post(route('admin.orders.courier', $order), [
                'courier_id' => $specialist->id,
                'assignment_role' => 'pickup_courier',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('assignment_role');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending',
            'courier_id' => null,
            'pickup_courier_id' => null,
            'delivery_courier_id' => null,
        ]);

        // Existing records are still readable during the migration, but the
        // dashboard exposes one unified courier rather than the retired
        // pickup/delivery fields.
        $legacy = $this->pendingOrder($merchant, $province);
        $legacy->update(['pickup_courier_id' => $specialist->id]);

        $this->actingAs($admin)
            ->getJson('/dashboard/orders?detail='.$legacy->id)
            ->assertOk()
            ->assertJsonPath('order.courier.id', $specialist->id)
            ->assertJsonMissingPath('order.pickup_courier')
            ->assertJsonMissingPath('order.delivery_courier');
    }

    public function test_legacy_specialist_cannot_read_the_direct_order_api_queue(): void
    {
        $merchant = User::query()->where('username', 'تاجر')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $specialist = User::withoutGlobalScopes()->create([
            'tenant_id' => User::query()->where('username', 'مندوب')->value('tenant_id'),
            'name' => 'مندوب توصيل قديم',
            'username' => 'legacy-delivery-'.random_int(1000, 9999),
            'phone' => '079'.str_pad((string) random_int(1, 9_999_999), 7, '0', STR_PAD_LEFT),
            'password' => 'password',
            'role' => 'delivery_courier',
            'status' => 'active',
            'vehicle' => 'bike',
        ]);
        $specialist->provinces()->syncWithoutDetaching([$province->id => ['is_primary' => true]]);
        $this->pendingOrder($merchant, $province);

        Sanctum::actingAs($specialist);
        $this->getJson('/api/v1/orders')->assertForbidden();
    }

    private function pendingOrder(User $merchant, object $province): Order
    {
        return Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => 'ALM-SINGLE-COURIER-'.random_int(100000, 999999),
            'source' => 'merchant',
            'customer_name_ar' => 'عميل اختبار مندوب واحد',
            'customer_name_en' => 'Single courier test customer',
            'phone' => '078'.str_pad((string) random_int(1, 9_999_999), 7, '0', STR_PAD_LEFT),
            'address_ar' => 'بغداد — الكرادة',
            'address_en' => 'Baghdad — Karrada',
            'delivery_vehicle' => 'normal',
            'price' => 25_000,
            'fee' => 2_500,
            'status' => 'pending',
            'workflow_stage' => 'created',
            'province_id' => $province->id,
            'date' => today(),
            'pickup_deadline_at' => now()->addMinutes(30),
        ]);
    }
}
