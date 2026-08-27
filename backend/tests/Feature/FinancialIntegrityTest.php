<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PricingRule;
use App\Models\Province;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\OrderWorkflowService;
use App\Services\PricingService;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialIntegrityTest extends TestCase
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

    public function test_a_delivery_posts_net_courier_collection_and_deducts_the_company_fee_from_qi_credit(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $startingBalance = (int) $merchant->wallet->balance;
        $startingCourierBalance = (int) $courier->wallet->balance;

        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => 'ALM-SETTLEMENT-001',
            'source' => 'merchant',
            'customer_name_ar' => 'عميل تحصيل',
            'customer_name_en' => 'Collection customer',
            'phone' => '07700000001',
            'address_ar' => 'بغداد',
            'address_en' => 'Baghdad',
            'delivery_vehicle' => 'normal',
            'price' => 30000,
            'fee' => 4500,
            'status' => 'courier',
            'workflow_stage' => 'out_for_delivery',
            'courier_id' => $courier->id,
            'delivery_courier_id' => $courier->id,
            'province_id' => $province->id,
            'date' => today(),
        ]);

        app(OrderWorkflowService::class)->changeStatus($order, 'delivered', $courier);

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame($startingBalance + 30000, (int) $merchant->wallet->fresh()->balance);
        $this->assertSame($startingCourierBalance - 4500, (int) $courier->wallet->fresh()->balance);
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'user_id' => $courier->id,
            'type' => 'collected',
            'amount' => 25500,
            'direction' => 1,
        ]);
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'user_id' => $merchant->id,
            'type' => 'settlement',
            'amount' => 30000,
            'direction' => 1,
        ]);
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'user_id' => $courier->id,
            'type' => 'delivery_fee',
            'amount' => 4500,
            'direction' => -1,
        ]);
        $this->assertSame(
            $startingBalance + 30000,
            (int) Tenant::findOrFail($merchant->tenant_id)->wallet_balance,
        );

        // A duplicate status submission must remain a no-op financially.
        app(OrderWorkflowService::class)->changeStatus($order, 'delivered', $courier);
        $this->assertSame(1, Transaction::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('user_id', $merchant->id)
            ->where('type', 'settlement')
            ->count());
        $this->assertSame($startingBalance + 30000, (int) $merchant->wallet->fresh()->balance);
        $this->assertSame($startingCourierBalance - 4500, (int) $courier->wallet->fresh()->balance);
    }

    public function test_pricing_uses_the_merchant_primary_province_as_route_origin(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $origin = $merchant->provinces()->wherePivot('is_primary', true)->firstOrFail();
        $otherOrigin = Province::query()->whereKeyNot($origin->id)->firstOrFail();
        $destination = Province::query()->whereKeyNot($origin->id)->orderByDesc('id')->firstOrFail();
        $platform = Tenant::platform();

        PricingRule::withoutGlobalScopes()->create([
            'tenant_id' => $platform->id,
            'origin_province_id' => $otherOrigin->id,
            'destination_province_id' => $destination->id,
            'vehicle' => 'normal',
            'base_fee' => 9999,
            'return_fee' => 1111,
            'priority' => 1,
            'is_active' => true,
            'name_ar' => 'قاعدة مصدر خاطئ',
        ]);
        $matching = PricingRule::withoutGlobalScopes()->create([
            'tenant_id' => $platform->id,
            'origin_province_id' => $origin->id,
            'destination_province_id' => $destination->id,
            'vehicle' => 'normal',
            'base_fee' => 3200,
            'return_fee' => 800,
            'priority' => 10,
            'is_active' => true,
            'name_ar' => 'قاعدة المصدر الصحيح',
        ]);

        $quote = app(PricingService::class)->quote($merchant, $destination->id, null, 'normal', 0, 0);

        $this->assertSame($matching->id, $quote['rule']?->id);
        $this->assertSame(3200, $quote['fee']);
        $this->assertSame(800, $quote['return_fee']);
    }
}
