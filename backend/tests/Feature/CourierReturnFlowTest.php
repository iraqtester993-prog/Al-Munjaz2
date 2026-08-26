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

    public function test_courier_return_requires_a_physical_handback_confirmation_before_posting_its_fee(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $price = 32000;
        $returnFee = 2500;
        $startingBudget = (int) $courier->wallet->budget;

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
            'fee' => 3000,
            'return_fee' => 3000,
            'status' => 'courier',
            'workflow_stage' => 'out_for_delivery',
            'courier_id' => $courier->id,
            'province_id' => $province->id,
            'date' => today(),
        ]);

        $courier->wallet->decrement('budget', $price);
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

        $this->actingAs($courier)
            ->post("/app/orders/{$order->id}/return", [
                'fee_mode' => 'fee',
                'return_fee_applied' => $returnFee,
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertSame('returned', $order->status);
        $this->assertSame('return_pending_merchant', $order->workflow_stage);
        $this->assertSame(3000, (int) $order->return_fee);
        $this->assertSame($returnFee, (int) $order->return_fee_applied);
        $this->assertNotNull($order->returned_at);
        $this->assertNull($order->returned_to_merchant_at);
        $this->assertNull($order->return_fee_charged_at);
        $this->assertSame($startingBudget, (int) $courier->wallet->fresh()->budget);
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
        $this->assertNotNull($order->return_fee_charged_at);
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'user_id' => $courier->id,
            'type' => 'delivery_fee',
            'amount' => $returnFee,
            'direction' => -1,
        ]);
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

        // The second confirmation must not post a duplicate fee transaction.
        $this->actingAs($courier)
            ->post("/app/orders/{$order->id}/return-to-merchant")
            ->assertSessionHasErrors('order');

        $this->assertSame(1, Transaction::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('user_id', $courier->id)
            ->where('type', 'delivery_fee')
            ->where('direction', -1)
            ->count());
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

            $wallet->decrement('budget', $price);
            Transaction::withoutGlobalScopes()->create([
                'tenant_id' => $courier->tenant_id,
                'user_id' => $courier->id,
                'type' => 'paid_order',
                'amount' => $price,
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
            $this->assertSame(1, Transaction::withoutGlobalScopes()
                ->where('order_id', $order->id)
                ->where('user_id', $courier->id)
                ->where('type', 'budget_release')
                ->count());
        }
    }
}
