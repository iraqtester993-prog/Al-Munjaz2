<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderWorkflowTransitionGuardTest extends TestCase
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

    public function test_administrator_cannot_skip_the_normal_order_lifecycle_with_a_note(): void
    {
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $merchant = User::query()->where('username', 'تاجر')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => 'ALM-TRANSITION-GUARD-'.random_int(100000, 999999),
            'source' => 'merchant',
            'customer_name_ar' => 'عميل اختبار الحالات',
            'customer_name_en' => 'Workflow test customer',
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
        ]);

        $this->actingAs($admin)
            ->post(route('admin.orders.status', $order), [
                'status' => 'delivered',
                'note' => 'لا ينبغي أن تتجاوز هذه الملاحظة مراحل الطلب.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('order');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending',
            'workflow_stage' => 'created',
        ]);
        $this->assertDatabaseMissing('order_status_logs', [
            'order_id' => $order->id,
            'to_status' => 'delivered',
        ]);
    }
}
