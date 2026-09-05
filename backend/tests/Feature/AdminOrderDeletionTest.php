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

class AdminOrderDeletionTest extends TestCase
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

    public function test_admin_can_soft_delete_an_unassigned_pending_order(): void
    {
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $merchant = User::query()->where('username', 'تاجر')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => 'ALM-ADMIN-DELETE-'.random_int(100000, 999999),
            'source' => 'merchant',
            'customer_name_ar' => 'عميل اختبار حذف الإدارة',
            'customer_name_en' => 'Admin deletion test customer',
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

        $this->actingAs($admin)
            ->delete(route('admin.orders.destroy', $order))
            ->assertRedirect();

        $this->assertSoftDeleted('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'order.soft_deleted_by_admin',
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'user_id' => $admin->id,
        ]);
    }
}
