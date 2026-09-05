<?php

namespace Tests\Feature;

use App\Models\FinanceRequest;
use App\Models\Order;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MobileFinancePresentationTest extends TestCase
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

    public function test_merchant_archive_uses_persisted_date_status_and_governorate_filters(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $order = Order::withoutGlobalScopes()
            ->where('tenant_id', $merchant->tenant_id)
            ->where('status', 'returned')
            ->whereNotNull('province_id')
            ->firstOrFail();
        $order->update(['archived_at' => now()]);

        $response = $this->actingAs($merchant)
            ->get('/app/reports?status=returned&detail_status=returned&province_id='.$order->province_id.'&from='.$order->date->toDateString().'&to='.$order->date->toDateString());

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/Reports')
                ->where('filters.status', 'returned')
                ->where('filters.province_id', $order->province_id)
                ->where('filters.from', $order->date->toDateString())
                ->where('filters.to', $order->date->toDateString())
                ->has('summary.status_counts.returned')
                ->has('statusOptions')
                ->has('provinceOptions')
                ->has('provinceDistribution')
                ->where('detailStatus', 'returned')
                ->has('orders', 1)
                ->where('orders.0.id', $order->id));
    }

    public function test_archive_excludes_unarchived_delivered_orders_until_they_are_archived(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $provinceId = Order::withoutGlobalScopes()
            ->where('tenant_id', $merchant->tenant_id)
            ->whereNotNull('province_id')
            ->value('province_id');

        $delivered = Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => 'RPT-DELIVERED-HISTORY',
            'source' => 'merchant',
            'customer_name_ar' => 'عميل تسليم الأرشيف',
            'customer_name_en' => 'Delivered archive customer',
            'phone' => '07700000777',
            'address_ar' => 'بغداد — الكرادة',
            'address_en' => 'Baghdad — Karrada',
            'price' => 42_000,
            'fee' => 3_000,
            'status' => 'delivered',
            'workflow_stage' => 'delivered',
            'delivered_at' => now(),
            'province_id' => $provinceId,
            'date' => today(),
        ]);

        $this->assertNull($delivered->archived_at);

        $this->actingAs($merchant)
            ->getJson("/app/orders?detail={$delivered->id}&archive=1")
            ->assertNotFound();

        $this->actingAs($merchant)
            ->get('/app/reports?status=delivered&detail_status=delivered')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/Reports')
                ->where('filters.status', 'delivered')
                ->where('summary.delivered_count', 0)
                ->where('detailStatus', 'delivered')
                ->has('orders', 0));

        // The same persisted marker is used whether a courier archives it
        // manually or the nightly scheduler archives it automatically.
        $delivered->update(['archived_at' => now()]);
        $expectedDelivered = Order::withoutGlobalScopes()
            ->where('tenant_id', $merchant->tenant_id)
            ->where('status', 'delivered')
            ->whereNotNull('archived_at')
            ->count();

        $this->actingAs($merchant)
            ->get('/app/reports?status=delivered&detail_status=delivered')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/Reports')
                ->where('filters.status', 'delivered')
                ->where('summary.delivered_count', $expectedDelivered)
                ->where('detailStatus', 'delivered')
                ->has('orders', $expectedDelivered)
                ->where('orders.0.id', $delivered->id));
    }

    public function test_merchant_report_overview_uses_aggregates_and_details_are_cursor_paginated(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $provinceId = Order::withoutGlobalScopes()
            ->where('tenant_id', $merchant->tenant_id)
            ->whereNotNull('province_id')
            ->value('province_id');
        Order::withoutGlobalScopes()
            ->where('tenant_id', $merchant->tenant_id)
            ->where('status', 'returned')
            ->update(['archived_at' => now()]);
        $expectedReturned = Order::withoutGlobalScopes()
            ->where('tenant_id', $merchant->tenant_id)
            ->where('status', 'returned')
            ->count() + 30;

        foreach (range(1, 30) as $number) {
            Order::withoutGlobalScopes()->create([
                'tenant_id' => $merchant->tenant_id,
                'track_no' => 'RPT-PERF-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'source' => 'merchant',
                'customer_name_ar' => 'عميل تقرير '.$number,
                'customer_name_en' => 'Report customer '.$number,
                'phone' => '077000'.str_pad((string) $number, 4, '0', STR_PAD_LEFT),
                'address_ar' => 'عنوان التقرير '.$number,
                'address_en' => 'Report address '.$number,
                'price' => 1000 + $number,
                'status' => 'returned',
                'workflow_stage' => 'returned_to_merchant',
                'archived_at' => now(),
                'province_id' => $provinceId,
                'date' => today(),
                'created_by' => $merchant->id,
            ]);
        }

        $this->actingAs($merchant)
            ->get('/app/reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/Reports')
                ->where('detailStatus', null)
                ->has('orders', 0)
                ->where('orderPagination.has_more', false)
                ->where('summary.returned_count', $expectedReturned));

        $this->actingAs($merchant)
            ->get('/app/reports?detail_status=returned')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/Reports')
                ->where('detailStatus', 'returned')
                ->has('orders', 25)
                ->where('orderPagination.has_more', true)
                ->has('orderPagination.next_cursor'));
    }

    public function test_courier_can_add_declared_cash_budget_immediately_with_a_ledger_audit(): void
    {
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $startingBudget = (int) $courier->wallet->budget;
        $startingBudgetBalance = (int) $courier->wallet->budget_balance;

        $this->actingAs($courier)
            ->get('/app/wallet?intent=budget')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/Wallet')
                ->where('isCourier', true)
                ->where('intent', 'budget')
                ->where('budget', $startingBudget)
                ->where('budget_balance', $startingBudgetBalance)
                ->has('summary.completed_deliveries')
                ->has('summary.returned_deliveries')
                ->has('summary.collections_total')
                ->has('summary.company_fees_total')
                ->has('summary.cash_on_hand')
                ->has('transactions'));

        $this->actingAs($courier)
            ->post('/app/wallet/budget', ['amount' => 100000, 'note' => 'نقد متاح لاستلام الطلبات'])
            ->assertRedirect();

        $this->assertSame($startingBudget + 100000, (int) $courier->wallet->fresh()->budget);
        $this->assertSame($startingBudgetBalance + 100000, (int) $courier->wallet->fresh()->budget_balance);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $courier->id,
            'type' => FinanceRequest::BUDGET_RECHARGE,
            'amount' => 100000,
            'direction' => 1,
            'finance_request_id' => null,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $courier->id,
            'action' => 'wallet.courier_budget_added',
        ]);

        $this->actingAs($courier)
            ->post('/app/wallet/budget/reduce', ['amount' => 40_000, 'note' => 'تخفيض نقد فائض'])
            ->assertRedirect();

        $this->assertSame($startingBudget + 60_000, (int) $courier->wallet->fresh()->budget);
        $this->assertSame($startingBudgetBalance + 60_000, (int) $courier->wallet->fresh()->budget_balance);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $courier->id,
            'type' => 'budget_deduct',
            'amount' => 40_000,
            'direction' => -1,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $courier->id,
            'action' => 'wallet.courier_budget_reduced',
        ]);

        $this->actingAs($courier)
            ->post('/app/wallet/budget/reduce', ['amount' => $startingBudgetBalance + 60_001])
            ->assertRedirect()
            ->assertSessionHasErrors('finance');
    }
}
