<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PricingRule;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DeliveryFeeSettingFlowTest extends TestCase
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

    public function test_dashboard_default_delivery_fee_is_saved_on_new_orders_and_exposed_to_mobile_details(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('username', 'تاجر')->firstOrFail();

        $this->saveDefaultDeliveryFee($admin, 6250);

        $firstOrder = $this->createMobileOrder($merchant, 'عميل سعر التوصيل الأول');

        $this->assertSame(6250, (int) $firstOrder->fee);
        $this->assertNull($firstOrder->pricing_rule_id);

        // The mobile order sheet receives the persisted quote, rather than
        // reading the live setting. A later settings change therefore cannot
        // silently reprice an existing delivery.
        $this->actingAs($merchant)
            ->get('/app/orders?filter=pending')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/Orders')
                ->where('filter', 'pending')
                ->where('orders.0.id', $firstOrder->id)
                ->where('orders.0.fee', 6250));

        $this->saveDefaultDeliveryFee($admin, 7400);

        $this->assertSame(6250, (int) Order::withoutGlobalScopes()->findOrFail($firstOrder->id)->fee);

        $secondOrder = $this->createMobileOrder($merchant, 'عميل سعر التوصيل الثاني');

        $this->assertSame(7400, (int) $secondOrder->fee);
        $this->assertSame(7400, (int) Setting::get('delivery_fee'));
    }

    public function test_an_active_pricing_rule_keeps_priority_over_the_default_delivery_fee(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('username', 'تاجر')->firstOrFail();

        $this->saveDefaultDeliveryFee($admin, 7100);

        $rule = PricingRule::withoutGlobalScopes()->create([
            'tenant_id' => Tenant::platform()->id,
            'merchant_id' => $merchant->id,
            'vehicle' => 'normal',
            'min_weight_grams' => 0,
            'base_fee' => 3850,
            'return_fee' => 900,
            'priority' => 1,
            'is_active' => true,
            'name_ar' => 'سعر خاص للتاجر',
        ]);

        $order = $this->createMobileOrder($merchant, 'عميل قاعدة التسعير');

        $this->assertSame(3850, (int) $order->fee);
        $this->assertSame(900, (int) $order->return_fee);
        $this->assertSame($rule->id, (int) $order->pricing_rule_id);
    }

    private function saveDefaultDeliveryFee(User $admin, int $fee): void
    {
        $this->actingAs($admin)
            ->post('/dashboard/settings', [
                'brand_name' => 'المنجز السريع',
                'brand_tagline' => 'منصة توصيل متكاملة',
                'support_phone' => '07700000000',
                'support_email' => 'support@example.test',
                'currency' => 'IQD',
                'delivery_fee' => $fee,
                'order_expiry_minutes' => 30,
                'pickup_eta_minutes' => 20,
            ])
            ->assertRedirect();
    }

    private function createMobileOrder(User $merchant, string $customer): Order
    {
        $this->actingAs($merchant)
            ->post('/app/orders', [
                'customer_name_ar' => $customer,
                'phone' => '077'.str_pad((string) random_int(1, 9_999_999), 8, '0', STR_PAD_LEFT),
                'address_ar' => 'بغداد — الكرادة',
                'pickup_latitude' => 33.3152412,
                'pickup_longitude' => 44.3660731,
                'pickup_location_label' => 'متجر الاختبار — الكرادة',
                'delivery_vehicle' => 'normal',
                'price' => 50000,
            ])
            ->assertRedirect();

        return Order::withoutGlobalScopes()
            ->where('customer_name_ar', $customer)
            ->latest('id')
            ->firstOrFail();
    }
}
