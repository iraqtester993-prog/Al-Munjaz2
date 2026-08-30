<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MerchantPendingOrderDeletionTest extends TestCase
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

    public function test_merchant_can_soft_delete_an_unassigned_pending_order_and_admin_can_restore_it(): void
    {
        $merchant = User::query()->where('username', 'تاجر')->firstOrFail();
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $order = $this->makePendingOrder($merchant);

        $this->actingAs($merchant)
            ->delete(route('app.orders.destroy', $order))
            ->assertRedirect();

        $this->assertSoftDeleted('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'order.soft_deleted_by_merchant',
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'user_id' => $merchant->id,
        ]);

        $this->actingAs($admin)
            ->get('/dashboard/orders?filter=deleted')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Orders')
                ->where('orders.data.0.id', $order->id)
                ->where('orders.data.0.deleted_at', fn ($value) => filled($value))
                ->where('counts.deleted', 1));

        $this->actingAs($admin)
            ->post("/dashboard/orders/{$order->id}/restore")
            ->assertRedirect();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'order.restored_by_admin',
            'subject_type' => 'order',
            'subject_id' => $order->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_merchant_cannot_delete_an_assigned_or_non_pending_order(): void
    {
        $merchant = User::query()->where('username', 'تاجر')->firstOrFail();
        $courier = User::query()->where('username', 'مندوب')->firstOrFail();
        $order = $this->makePendingOrder($merchant);
        $order->forceFill([
            'status' => 'approved',
            'workflow_stage' => 'awaiting_pickup',
            'courier_id' => $courier->id,
        ])->save();

        $this->actingAs($merchant)
            ->delete(route('app.orders.destroy', $order))
            ->assertUnprocessable();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'approved',
            'deleted_at' => null,
        ]);
    }

    public function test_merchant_cannot_delete_an_order_with_a_financial_posting_or_another_merchants_order(): void
    {
        $merchant = User::query()->where('username', 'تاجر')->firstOrFail();
        $financialOrder = $this->makePendingOrder($merchant);

        Transaction::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'user_id' => $merchant->id,
            'type' => 'settlement',
            'amount' => 1_000,
            'direction' => 1,
            'ref' => $financialOrder->track_no,
            'order_id' => $financialOrder->id,
            'date' => today(),
            'note' => 'حركة اختبار مرتبطة بالطلب.',
        ]);

        $this->actingAs($merchant)
            ->delete(route('app.orders.destroy', $financialOrder))
            ->assertUnprocessable();

        $secondMerchant = User::create([
            'tenant_id' => $merchant->tenant_id,
            'name' => 'تاجر آخر',
            'username' => 'merchant-delete-guard',
            'phone' => '07919990077',
            'password' => 'Password123!',
            'role' => 'merchant',
            'status' => 'active',
            'shop_name' => 'متجر آخر',
            'address' => 'بغداد — الكرادة',
        ]);
        $secondMerchant->provinces()->syncWithoutDetaching([
            $merchant->provinces()->firstOrFail()->id => ['is_primary' => true],
        ]);
        $otherOrder = $this->makePendingOrder($secondMerchant);

        $this->actingAs($merchant)
            ->delete(route('app.orders.destroy', $otherOrder))
            ->assertForbidden();

        $this->assertDatabaseHas('orders', ['id' => $financialOrder->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('orders', ['id' => $otherOrder->id, 'deleted_at' => null]);
    }

    private function makePendingOrder(User $merchant): Order
    {
        $province = $merchant->provinces()->firstOrFail();

        return Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => 'ALM-DELETE-'.random_int(100000, 999999),
            'source' => 'merchant',
            'customer_name_ar' => 'عميل اختبار الحذف',
            'customer_name_en' => 'Delete test customer',
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
