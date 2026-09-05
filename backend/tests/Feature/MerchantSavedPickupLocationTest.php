<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MerchantSavedPickupLocationTest extends TestCase
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

    public function test_merchant_saved_shop_location_is_the_default_but_a_complete_pwa_pickup_tuple_can_override_one_order(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $savedLocation = [
            'merchant_pickup_latitude' => 33.3152412,
            'merchant_pickup_longitude' => 44.3660731,
            'merchant_pickup_location_label' => 'متجر الاختبار — الكرادة',
        ];

        $this->actingAs($merchant)
            ->post(route('profile.update'), [
                'name' => $merchant->name,
                'phone' => $merchant->phone,
                'shop_name' => $merchant->shop_name,
                'address' => $merchant->address,
                ...$savedLocation,
            ])
            ->assertRedirect();

        $merchant->refresh();
        $this->assertSame('33.3152412', $merchant->merchant_pickup_latitude);
        $this->assertSame('44.3660731', $merchant->merchant_pickup_longitude);
        $this->assertSame('متجر الاختبار — الكرادة', $merchant->merchant_pickup_location_label);
        $this->assertNotNull($merchant->merchant_pickup_location_updated_at);

        // A complete location selected while creating this order must win
        // without changing the merchant's saved shop point.
        $this->actingAs($merchant)
            ->post('/app/orders', [
                'customer_name_ar' => 'عميل موقع المتجر الثابت',
                'phone' => '07710000001',
                'address_ar' => 'بغداد — المنصور',
                'pickup_latitude' => 30.0000000,
                'pickup_longitude' => 47.0000000,
                'pickup_location_label' => 'موقع مُرسل من عميل قديم',
                'delivery_vehicle' => 'normal',
                'price' => 25000,
            ])
            ->assertRedirect();

        $order = Order::withoutGlobalScopes()
            ->where('customer_name_ar', 'عميل موقع المتجر الثابت')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('30.0000000', $order->pickup_latitude);
        $this->assertSame('47.0000000', $order->pickup_longitude);
        $this->assertSame('موقع مُرسل من عميل قديم', $order->pickup_location_label);

        // Omitting all three fields deliberately falls back to the shop
        // point, which preserves older clients and the normal default flow.
        $this->actingAs($merchant)
            ->post('/app/orders', [
                'customer_name_ar' => 'عميل موقع المتجر الافتراضي',
                'phone' => '07710000002',
                'address_ar' => 'بغداد — الكرادة',
                'delivery_vehicle' => 'normal',
                'price' => 25000,
            ])
            ->assertRedirect();

        $defaultOrder = Order::withoutGlobalScopes()
            ->where('customer_name_ar', 'عميل موقع المتجر الافتراضي')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('33.3152412', $defaultOrder->pickup_latitude);
        $this->assertSame('44.3660731', $defaultOrder->pickup_longitude);
        $this->assertSame('متجر الاختبار — الكرادة', $defaultOrder->pickup_location_label);

        $merchant->update([
            'merchant_pickup_latitude' => 33.3100000,
            'merchant_pickup_longitude' => 44.3000000,
            'merchant_pickup_location_label' => 'الموقع الجديد للتاجر',
        ]);

        $order->refresh();
        $this->assertSame('30.0000000', $order->pickup_latitude);
        $this->assertSame('47.0000000', $order->pickup_longitude);
        $this->assertSame('موقع مُرسل من عميل قديم', $order->pickup_location_label);
    }

    public function test_token_api_uses_the_saved_shop_location_when_legacy_client_omits_the_tuple(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $merchant->update([
            'merchant_pickup_latitude' => 33.3211000,
            'merchant_pickup_longitude' => 44.4012000,
            'merchant_pickup_location_label' => 'مخزن التاجر — بغداد',
        ]);

        Sanctum::actingAs($merchant);

        $response = $this->postJson('/api/v1/orders', [
            'customer_name_ar' => 'عميل واجهة API',
            'phone' => '07810000001',
            'address_ar' => 'بغداد — الأعظمية',
            'delivery_vehicle' => 'bike',
            'price' => 15000,
            'province_id' => $province->id,
        ])->assertCreated();

        $response
            ->assertJsonPath('data.pickup_latitude', 33.3211)
            ->assertJsonPath('data.pickup_longitude', 44.4012)
            ->assertJsonPath('data.pickup_location_label', 'مخزن التاجر — بغداد');

        $this->postJson('/api/v1/orders', [
            'customer_name_ar' => 'عميل واجهة API بموقع خاص',
            'phone' => '07810000002',
            'address_ar' => 'بغداد — المنصور',
            'delivery_vehicle' => 'bike',
            'price' => 15000,
            'province_id' => $province->id,
            'pickup_latitude' => 33.3555555,
            'pickup_longitude' => 44.3888888,
            'pickup_location_label' => 'نقطة استلام هذا الطلب فقط',
        ])
            ->assertCreated()
            ->assertJsonPath('data.pickup_latitude', 33.3555555)
            ->assertJsonPath('data.pickup_longitude', 44.3888888)
            ->assertJsonPath('data.pickup_location_label', 'نقطة استلام هذا الطلب فقط');
    }

    public function test_partial_explicit_pickup_tuple_is_rejected_even_when_the_shop_location_is_saved(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $merchant->update([
            'merchant_pickup_latitude' => 33.3152412,
            'merchant_pickup_longitude' => 44.3660731,
            'merchant_pickup_location_label' => 'موقع المتجر المحفوظ',
        ]);

        $webPayload = [
            'customer_name_ar' => 'عميل موقع جزئي عبر الويب',
            'phone' => '07710000003',
            'address_ar' => 'بغداد — الكرادة',
            'delivery_vehicle' => 'normal',
            'price' => 25000,
            'pickup_latitude' => 33.3555555,
        ];

        $this->actingAs($merchant)
            ->post('/app/orders', $webPayload)
            ->assertSessionHasErrors(['pickup_longitude', 'pickup_location_label']);

        Sanctum::actingAs($merchant);

        $this->postJson('/api/v1/orders', [
            ...$webPayload,
            'customer_name_ar' => 'عميل موقع جزئي عبر API',
            'phone' => '07810000003',
            'province_id' => $province->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pickup_longitude', 'pickup_location_label']);
    }

    public function test_courier_cannot_set_a_merchant_shop_location_through_profile_update(): void
    {
        $courier = User::where('username', 'مندوب')->firstOrFail();

        $this->actingAs($courier)
            ->post(route('profile.update'), [
                'name' => $courier->name,
                'phone' => $courier->phone,
                'vehicle' => $courier->vehicle,
                'merchant_pickup_latitude' => 33.3152412,
                'merchant_pickup_longitude' => 44.3660731,
                'merchant_pickup_location_label' => 'لا يجب حفظ هذا الموقع',
            ])
            ->assertForbidden();

        $courier->refresh();
        $this->assertNull($courier->merchant_pickup_latitude);
        $this->assertNull($courier->merchant_pickup_longitude);
        $this->assertNull($courier->merchant_pickup_location_label);
    }
}
