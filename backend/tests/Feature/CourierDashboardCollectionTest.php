<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CourierDashboardCollectionTest extends TestCase
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

    public function test_courier_dashboard_reports_net_delivery_fee_after_administration_deduction(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();

        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => 'ALM-NET-COLLECTION-5000',
            'source' => 'merchant',
            'customer_name_ar' => 'عميل المتحصل اليوم',
            'customer_name_en' => 'Today collection customer',
            'phone' => '07700005000',
            'address_ar' => 'بغداد',
            'address_en' => 'Baghdad',
            'delivery_vehicle' => 'normal',
            // The product price is intentionally much larger. It must not
            // be counted as courier earnings on the home card.
            'price' => 120000,
            'fee' => 5000,
            'admin_deduction_applied' => 2000,
            'status' => 'delivered',
            'workflow_stage' => 'delivered',
            'courier_id' => $courier->id,
            'province_id' => $province->id,
            // The home card tracks today's completed work, not the day the
            // merchant first created an order.
            'date' => today()->subDay(),
            'delivered_at' => now(),
        ]);

        $this->actingAs($courier)
            ->get('/app')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/CourierHome')
                // 5,000 delivery fee - 2,000 administration deduction.
                ->where('stats.collectedToday', 3000)
                ->where('stats.deliveredToday', 1)
            );
    }

    public function test_courier_dashboard_exposes_the_configured_order_acceptance_time(): void
    {
        $courier = User::where('username', 'مندوب')->firstOrFail();
        Setting::set('order_expiry_minutes', 45);

        $this->actingAs($courier)
            ->get('/app')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/CourierHome')
                ->where('orderExpiryMinutes', 45));

        // The cursor endpoint powers the "See all" control, so it must carry
        // the same setting for the fallback countdown after it loads more.
        $this->actingAs($courier)
            ->getJson('/app')
            ->assertOk()
            ->assertJsonPath('orderExpiryMinutes', 45);
    }
}
