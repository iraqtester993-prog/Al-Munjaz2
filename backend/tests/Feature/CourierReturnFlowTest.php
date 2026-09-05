<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Services\OrderWorkflowService;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourierReturnFlowTest extends TestCase
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

    public function test_courier_return_releases_the_product_budget_without_a_second_qi_deduction(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $price = 32000;
        $deliveryFee = 3000;
        $adminDeduction = 2000;
        $startingBudget = (int) $courier->wallet->budget;
        $startingBudgetBalance = (int) $courier->wallet->budget_balance;
        $startingBalance = (int) $courier->wallet->balance;

        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => 'ALM-RETURN-TWO-STEP',
            'source' => 'merchant',
            'customer_name_ar' => 'عميل إرجاع',
            'customer_name_en' => 'Return Customer',
            'phone' => '07701112233',
            'address_ar' => 'بغداد — الكرادة',
            'address_en' => 'Baghdad — Karrada',
            'delivery_vehicle' => 'normal',
            'price' => $price,
            'fee' => $deliveryFee,
            'return_fee' => $deliveryFee,
            'admin_deduction_applied' => $adminDeduction,
            'status' => 'courier',
            'workflow_stage' => 'out_for_delivery',
            'courier_id' => $courier->id,
            'province_id' => $province->id,
            'date' => today(),
        ]);

        // This order has already passed acceptance: only product price is
        // reserved from available budget and the fixed Qi deduction was
        // charged once at claim time.
        $courier->wallet->decrement('budget_balance', $price);
        $courier->wallet->decrement('balance', $adminDeduction);
        Transaction::withoutGlobalScopes()->create([
            'tenant_id' => $courier->tenant_id,
            'user_id' => $courier->id,
            'type' => 'paid_order',
            'amount' => $price,
            'direction' => -1,
            'ref' => $order->track_no,
            'order_id' => $order->id,
            'date' => today(),
            'note' => 'حجز اختبار للإرجاع',
        ]);
        Transaction::withoutGlobalScopes()->create([
            'tenant_id' => $courier->tenant_id,
            'user_id' => $courier->id,
            'type' => 'commission',
            'amount' => $adminDeduction,
            'direction' => -1,
            'ref' => $order->track_no,
            'order_id' => $order->id,
            'date' => today(),
            'note' => 'استقطاع إدارة اختبار عند القبول',
        ]);

        $courier->update([
            'current_latitude' => 33.3152412,
            'current_longitude' => 44.3660731,
            'location_updated_at' => now(),
        ]);

        $this->actingAs($courier)
            ->post("/app/orders/{$order->id}/return", [
                'fee_mode' => 'fee',
                'return_reason' => 'تعذر الوصول إلى العميل في عنوان التسليم.',
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertSame('returned', $order->status);
        $this->assertSame('return_pending_merchant', $order->workflow_stage);
        $this->assertSame(3000, (int) $order->return_fee);
        $this->assertSame($deliveryFee, (int) $order->return_fee_applied);
        $this->assertSame('fee', $order->return_fee_mode);
        $this->assertSame('تعذر الوصول إلى العميل في عنوان التسليم.', $order->return_reason);
        $this->assertNotNull($order->returned_at);
        $this->assertNull($order->returned_to_merchant_at);
        $this->assertNull($order->return_fee_charged_at);
        $this->assertSame($startingBudget, (int) $courier->wallet->fresh()->budget);
        $this->assertSame($startingBudgetBalance, (int) $courier->wallet->fresh()->budget_balance);
        $this->assertSame($startingBalance - $adminDeduction, (int) $courier->wallet->fresh()->balance);
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'user_id' => $courier->id,
            'type' => 'commission',
            'amount' => $adminDeduction,
            'direction' => -1,
        ]);
        $this->assertSame(0, Transaction::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('type', 'delivery_fee')
            ->count());
        $this->assertDatabaseHas('order_movements', [
            'order_id' => $order->id,
            'stage' => 'return_pending_merchant',
            'actor_id' => $courier->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $merchant->id,
            'type' => 'order',
            'title_ar' => $order->track_no,
        ]);

        $this->actingAs($courier)
            ->post("/app/orders/{$order->id}/return-to-merchant")
            ->assertRedirect();

        $order->refresh();
        $this->assertSame('returned', $order->status);
        $this->assertSame('returned_to_merchant', $order->workflow_stage);
        $this->assertNotNull($order->returned_to_merchant_at);
        $this->assertNull($order->return_fee_charged_at);
        $this->assertDatabaseHas('order_movements', [
            'order_id' => $order->id,
            'stage' => 'returned_to_merchant',
            'actor_id' => $courier->id,
        ]);
        $this->assertSame(2, Notification::withoutGlobalScopes()
            ->where('user_id', $merchant->id)
            ->where('type', 'order')
            ->where('title_ar', $order->track_no)
            ->count());

        // Confirmation only records the physical handback; it cannot post a
        // second administration deduction or the selected return fee.
        $this->actingAs($courier)
            ->post("/app/orders/{$order->id}/return-to-merchant")
            ->assertSessionHasErrors('order');

        $this->assertSame(1, Transaction::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('user_id', $courier->id)
            ->where('type', 'commission')
            ->where('direction', -1)
            ->count());
        $this->assertSame(0, Transaction::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('user_id', $courier->id)
            ->where('type', 'commission_refund')
            ->count());
    }

    public function test_free_return_refunds_the_actual_legacy_deduction_when_the_snapshot_is_missing(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $deduction = 2_000;
        $wallet = $courier->wallet()->firstOrFail();
        $startingBalance = (int) $wallet->balance;

        // This mirrors an order accepted before admin_deduction_applied was
        // introduced: the money is present in the immutable ledger, but the
        // newer snapshot column is null.
        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => 'ALM-RETURN-LEGACY-FREE',
            'source' => 'merchant',
            'customer_name_ar' => 'عميل إرجاع مجاني قديم',
            'customer_name_en' => 'Legacy free return customer',
            'phone' => '07701112244',
            'address_ar' => 'بغداد — الكرادة',
            'address_en' => 'Baghdad — Karrada',
            'delivery_vehicle' => 'normal',
            'price' => 20_000,
            'fee' => 3_000,
            'return_fee' => 3_000,
            'admin_deduction_applied' => null,
            'status' => 'courier',
            'workflow_stage' => 'out_for_delivery',
            'courier_id' => $courier->id,
            'province_id' => $province->id,
            'date' => today(),
        ]);

        $wallet->decrement('balance', $deduction);
        Transaction::withoutGlobalScopes()->create([
            'tenant_id' => $courier->tenant_id,
            'user_id' => $courier->id,
            'type' => 'commission',
            'amount' => $deduction,
            'direction' => -1,
            'ref' => $order->track_no,
            'order_id' => $order->id,
            'date' => today(),
            'note' => 'استقطاع إدارة قديم للاختبار',
        ]);
        $courier->update([
            'current_latitude' => 33.3152412,
            'current_longitude' => 44.3660731,
            'location_updated_at' => now(),
        ]);

        $this->actingAs($courier)
            ->post("/app/orders/{$order->id}/return", [
                'fee_mode' => 'none',
                'return_reason' => 'العميل اعتذر عن الاستلام.',
            ])
            ->assertRedirect();

        $this->assertSame('returned', $order->fresh()->status);
        $this->assertSame('none', $order->fresh()->return_fee_mode);
        $this->assertSame('العميل اعتذر عن الاستلام.', $order->fresh()->return_reason);
        $this->assertSame($startingBalance, (int) $wallet->fresh()->balance);
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'user_id' => $courier->id,
            'type' => 'commission_refund',
            'amount' => $deduction,
            'direction' => 1,
        ]);
    }

    public function test_each_non_delivery_terminal_status_releases_a_held_courier_budget_once(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $price = 12000;

        foreach (['cancelled', 'damaged', 'rejected'] as $index => $terminalStatus) {
            $wallet = $courier->wallet()->firstOrFail();
            $startingBudget = (int) $wallet->budget;
            $startingBudgetBalance = (int) $wallet->budget_balance;
            $order = Order::withoutGlobalScopes()->create([
                'tenant_id' => $merchant->tenant_id,
                'merchant_id' => $merchant->id,
                'created_by' => $merchant->id,
                'track_no' => 'ALM-TERMINAL-'.($index + 1),
                'source' => 'merchant',
                'customer_name_ar' => 'عميل حالة نهائية',
                'customer_name_en' => 'Terminal status customer',
                'phone' => '0770222333'.($index + 1),
                'address_ar' => 'بغداد',
                'address_en' => 'Baghdad',
                'delivery_vehicle' => 'normal',
                'price' => $price,
                'fee' => 3000,
                'status' => 'courier',
                'workflow_stage' => 'out_for_delivery',
                'courier_id' => $courier->id,
                'province_id' => $province->id,
                'date' => today(),
            ]);

            // Simulate a record reserved by the previous policy. The release
            // must return the actual ledger amount even though new claims
            // reserve price only.
            $wallet->decrement('budget_balance', $price + 3000);
            Transaction::withoutGlobalScopes()->create([
                'tenant_id' => $courier->tenant_id,
                'user_id' => $courier->id,
                'type' => 'paid_order',
                'amount' => $price + 3000,
                'direction' => -1,
                'ref' => $order->track_no,
                'order_id' => $order->id,
                'date' => today(),
                'note' => 'حجز اختبار للحالة النهائية',
            ]);

            app(OrderWorkflowService::class)->changeStatus($order, $terminalStatus, $courier);
            app(OrderWorkflowService::class)->changeStatus($order, $terminalStatus, $courier);

            $this->assertSame($terminalStatus, $order->fresh()->status);
            $this->assertSame($terminalStatus, $order->fresh()->workflow_stage);
            $this->assertSame($startingBudget, (int) $wallet->fresh()->budget);
            $this->assertSame($startingBudgetBalance, (int) $wallet->fresh()->budget_balance);
            $this->assertSame(1, Transaction::withoutGlobalScopes()
                ->where('order_id', $order->id)
                ->where('user_id', $courier->id)
                ->where('type', 'budget_release')
                ->count());
        }
    }
}
