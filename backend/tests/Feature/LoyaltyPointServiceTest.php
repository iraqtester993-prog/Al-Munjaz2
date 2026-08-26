<?php

namespace Tests\Feature;

use App\Models\LoyaltyEntry;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Wallet;
use App\Services\LoyaltyPointService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyPointServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::clear();
    }

    public function test_a_delivered_order_is_rewarded_once_without_touching_money_wallets(): void
    {
        $tenant = Tenant::platform();
        $courier = User::factory()->create([
            'tenant_id' => $tenant->id,
            'username' => 'loyalty-courier',
            'phone' => '07910000001',
            'role' => 'courier',
            'status' => 'active',
        ]);
        Wallet::create(['user_id' => $courier->id, 'balance' => 75000, 'budget' => 100000]);
        Setting::set(LoyaltyPointService::POINTS_PER_DELIVERY_KEY, 25);

        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'track_no' => 'ALM-LOYALTY-001',
            'source' => 'merchant',
            'customer_name_ar' => 'عميل النقاط',
            'customer_name_en' => 'Points customer',
            'phone' => '07910000002',
            'address_ar' => 'بغداد',
            'address_en' => 'Baghdad',
            'price' => 25000,
            'status' => 'delivered',
            'workflow_stage' => 'delivered',
            'courier_id' => $courier->id,
            'delivery_courier_id' => $courier->id,
            'date' => today(),
        ]);

        $service = app(LoyaltyPointService::class);
        $first = $service->creditForDelivery($order);
        $second = $service->creditForDelivery($order);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertSame(25, (int) $courier->loyaltyAccount()->firstOrFail()->balance);
        $this->assertSame(75000, (int) $courier->wallet()->firstOrFail()->balance);
        $this->assertSame(1, LoyaltyEntry::query()
            ->where('type', LoyaltyPointService::DELIVERY_REWARD)
            ->where('source_type', LoyaltyPointService::DELIVERY_SOURCE)
            ->where('source_id', $order->id)
            ->count());
    }

    public function test_a_legacy_delivered_order_without_an_eligible_courier_does_not_break_completion(): void
    {
        $tenant = Tenant::platform();
        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'track_no' => 'ALM-LOYALTY-LEGACY',
            'source' => 'merchant',
            'customer_name_ar' => 'عميل قديم',
            'phone' => '07910000003',
            'address_ar' => 'بغداد',
            'price' => 12000,
            'status' => 'delivered',
            'workflow_stage' => 'delivered',
            'date' => today(),
        ]);

        $this->assertNull(app(LoyaltyPointService::class)->creditForDelivery($order));
        $this->assertDatabaseCount('loyalty_entries', 0);
    }
}
