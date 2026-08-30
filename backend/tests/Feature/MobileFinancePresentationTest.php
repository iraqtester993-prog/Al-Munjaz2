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
            ->where('status', 'delivered')
            ->whereNotNull('province_id')
            ->firstOrFail();

        $response = $this->actingAs($merchant)
            ->get('/app/reports?status=delivered&detail_status=delivered&province_id='.$order->province_id.'&from='.$order->date->toDateString().'&to='.$order->date->toDateString());

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/Reports')
                ->where('filters.status', 'delivered')
                ->where('filters.province_id', $order->province_id)
                ->where('filters.from', $order->date->toDateString())
                ->where('filters.to', $order->date->toDateString())
                ->has('summary.status_counts.delivered')
                ->has('statusOptions')
                ->has('provinceOptions')
                ->has('provinceDistribution')
                ->where('detailStatus', 'delivered')
                ->has('orders', 1)
                ->where('orders.0.id', $order->id));
    }

    public function test_merchant_report_overview_uses_aggregates_and_details_are_cursor_paginated(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $provinceId = Order::withoutGlobalScopes()
            ->where('tenant_id', $merchant->tenant_id)
            ->whereNotNull('province_id')
            ->value('province_id');
        $expectedDelivered = Order::withoutGlobalScopes()
            ->where('tenant_id', $merchant->tenant_id)
            ->where('status', 'delivered')
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
                'status' => 'delivered',
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
                ->where('summary.delivered_count', $expectedDelivered));

        $this->actingAs($merchant)
            ->get('/app/reports?detail_status=delivered')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/Reports')
                ->where('detailStatus', 'delivered')
                ->has('orders', 25)
                ->where('orderPagination.has_more', true)
                ->has('orderPagination.next_cursor'));
    }

    public function test_courier_can_add_declared_cash_budget_immediately_with_a_ledger_audit(): void
    {
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $startingBudget = (int) $courier->wallet->budget;

        $this->actingAs($courier)
            ->get('/app/wallet?intent=budget')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/Wallet')
                ->where('isCourier', true)
                ->where('intent', 'budget')
                ->has('summary.completed_deliveries')
                ->has('summary.returned_deliveries')
                ->has('summary.collections_total')
                ->has('summary.cash_on_hand')
                ->has('transactions'));

        $this->actingAs($courier)
            ->post('/app/wallet/budget', ['amount' => 100000, 'note' => 'نقد متاح لاستلام الطلبات'])
            ->assertRedirect();

        $this->assertSame($startingBudget + 100000, (int) $courier->wallet->fresh()->budget);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $courier->id,
            'type' => \App\Models\FinanceRequest::BUDGET_RECHARGE,
            'amount' => 100000,
            'direction' => 1,
            'finance_request_id' => null,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $courier->id,
            'action' => 'wallet.courier_budget_added',
        ]);
    }
}
