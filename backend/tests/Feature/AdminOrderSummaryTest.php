<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Province;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminOrderSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::clear();
        $this->withoutVite();
        $this->seed(PlanSeeder::class);
        $this->seed(ProvinceSeeder::class);
        $this->seed(DemoSeeder::class);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    public function test_orders_summary_exposes_gross_amounts_for_every_status_without_being_narrowed_by_table_filters(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();

        $this->makeOrder($merchant, $province, 'ALM-SUMMARY-PENDING', 'pending', 120000, 5000);
        $this->makeOrder($merchant, $province, 'ALM-SUMMARY-DELIVERED', 'delivered', 45000, null);
        $this->makeOrder($merchant, $province, 'ALM-SUMMARY-LATE', 'pending', 10000, 1000, [
            'pickup_deadline_at' => now()->subMinute(),
        ]);

        $deleted = $this->makeOrder($merchant, $province, 'ALM-SUMMARY-DELETED', 'pending', 25000, 2500);
        $deleted->delete();

        $live = Order::withoutGlobalScope(TenantScope::class)->get();
        $deletedOrders = Order::withoutGlobalScope(TenantScope::class)->onlyTrashed()->get();
        $late = $live->filter(fn (Order $order) => ! in_array($order->status, Order::TERMINAL_STATUSES, true)
            && $order->pickup_deadline_at?->isPast());

        // Deliberately use a status and text filter that make the table empty.
        // The card summary remains global navigation data for this authorised
        // dashboard scope rather than a misleading summary of one page.
        $response = $this->actingAs($admin)
            ->get('/dashboard/orders?filter=delivered&q=not-a-real-order');

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Orders')
                ->has('orders.data', 0)
                ->has('summary'));

        $summary = $response->inertiaProps('summary');

        $this->assertSame($this->financialAggregate($live), $summary['all']);
        foreach (Order::STATUSES as $status) {
            $this->assertSame(
                $this->financialAggregate($live->where('status', $status)),
                $summary[$status],
                "Unexpected summary for {$status}."
            );
        }

        $this->assertSame($this->financialAggregate($late), $summary['late']);
        $this->assertSame($this->financialAggregate($deletedOrders), $summary['deleted']);

        // The existing count payload remains available for the compact tabs
        // while clients migrate to the monetary summary cards.
        $this->assertSame($summary['all']['count'], $response->inertiaProps('counts.all'));
        $this->assertSame($summary['pending']['count'], $response->inertiaProps('counts.pending'));
    }

    private function makeOrder(
        User $merchant,
        Province $province,
        string $trackNo,
        string $status,
        int $price,
        ?int $fee,
        array $attributes = [],
    ): Order {
        return Order::withoutGlobalScope(TenantScope::class)->create(array_merge([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => $trackNo,
            'source' => 'merchant',
            'customer_name_ar' => 'عميل ملخص الطلبات',
            'customer_name_en' => 'Order Summary Customer',
            'phone' => '077'.str_pad((string) random_int(1, 9_999_999), 8, '0', STR_PAD_LEFT),
            'address_ar' => 'بغداد — الكرادة',
            'address_en' => 'Baghdad — Karrada',
            'delivery_vehicle' => 'normal',
            'price' => $price,
            'fee' => $fee,
            'status' => $status,
            'workflow_stage' => $status === 'delivered' ? 'delivered' : 'created',
            'province_id' => $province->id,
            'date' => today(),
        ], $attributes));
    }

    /**
     * @param  iterable<Order>  $orders
     * @return array{count: int, amount: int, order_value: int, delivery_fee: int}
     */
    private function financialAggregate(iterable $orders): array
    {
        $orders = collect($orders);

        return [
            'count' => $orders->count(),
            'amount' => $orders->sum(fn (Order $order) => (int) $order->price + (int) ($order->fee ?? 0)),
            'order_value' => $orders->sum(fn (Order $order) => (int) $order->price),
            'delivery_fee' => $orders->sum(fn (Order $order) => (int) ($order->fee ?? 0)),
        ];
    }
}
