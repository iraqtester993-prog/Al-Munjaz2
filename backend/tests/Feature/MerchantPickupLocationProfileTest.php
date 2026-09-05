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

class MerchantPickupLocationProfileTest extends TestCase
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

    public function test_merchant_can_save_a_fixed_pickup_location_and_receive_it_on_their_profile(): void
    {
        $merchant = $this->merchant();

        $this->actingAs($merchant)
            ->post(route('profile.update'), $this->profilePayload($merchant, [
                'merchant_pickup_latitude' => 33.3152412,
                'merchant_pickup_longitude' => 44.3660731,
                'merchant_pickup_location_label' => '  متجر التاجر — الكرادة  ',
            ]))
            ->assertRedirect();

        $merchant->refresh();

        $this->assertSame('33.3152412', $merchant->merchant_pickup_latitude);
        $this->assertSame('44.3660731', $merchant->merchant_pickup_longitude);
        $this->assertSame('متجر التاجر — الكرادة', $merchant->merchant_pickup_location_label);
        $this->assertNotNull($merchant->merchant_pickup_location_updated_at);

        // Older installed profile forms do not post the new fields. Their
        // regular account edits must not clear a saved shop point.
        $this->actingAs($merchant)
            ->post(route('profile.update'), $this->profilePayload($merchant))
            ->assertRedirect();
        $this->assertSame('33.3152412', $merchant->fresh()->merchant_pickup_latitude);

        $this->actingAs($merchant)->get('/app/profile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/Profile')
                ->where('profile.merchant_pickup_location.latitude', 33.3152412)
                ->where('profile.merchant_pickup_location.longitude', 44.3660731)
                ->where('profile.merchant_pickup_location.label', 'متجر التاجر — الكرادة')
                ->has('profile.merchant_pickup_location.updated_at'));
    }

    public function test_a_submitted_merchant_pickup_location_must_include_every_value(): void
    {
        $merchant = $this->merchant();

        $this->actingAs($merchant)
            ->post(route('profile.update'), $this->profilePayload($merchant, [
                'merchant_pickup_latitude' => 33.3152412,
            ]))
            ->assertSessionHasErrors([
                'merchant_pickup_longitude',
                'merchant_pickup_location_label',
            ]);

        $merchant->refresh();
        $this->assertNull($merchant->merchant_pickup_latitude);
        $this->assertNull($merchant->merchant_pickup_longitude);
        $this->assertNull($merchant->merchant_pickup_location_label);
    }

    public function test_courier_cannot_store_a_merchant_shop_location(): void
    {
        $courier = User::query()->where('role', 'courier')->firstOrFail();

        $this->actingAs($courier)
            ->post(route('profile.update'), $this->profilePayload($courier, [
                'merchant_pickup_latitude' => 33.3152412,
                'merchant_pickup_longitude' => 44.3660731,
                'merchant_pickup_location_label' => 'لا يجب حفظه للمندوب',
            ]))
            ->assertForbidden();

        $courier->refresh();
        $this->assertNull($courier->merchant_pickup_latitude);
        $this->assertNull($courier->merchant_pickup_longitude);
    }

    public function test_new_orders_default_to_the_saved_merchant_location_and_can_override_it_for_one_order(): void
    {
        $merchant = $this->merchant();
        $merchant->update([
            'merchant_pickup_latitude' => 33.3152412,
            'merchant_pickup_longitude' => 44.3660731,
            'merchant_pickup_location_label' => 'مخزن المتجر الأساسي',
            'merchant_pickup_location_updated_at' => now(),
        ]);

        $savedDefaultPayload = $this->orderPayload([
            'customer_name_ar' => 'عميل الموقع الافتراضي',
        ]);
        unset(
            $savedDefaultPayload['pickup_latitude'],
            $savedDefaultPayload['pickup_longitude'],
            $savedDefaultPayload['pickup_location_label'],
        );

        $this->actingAs($merchant)
            ->post('/app/orders', $savedDefaultPayload)
            ->assertRedirect();

        $defaultLocationOrder = Order::withoutGlobalScopes()
            ->where('customer_name_ar', 'عميل الموقع الافتراضي')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('33.3152412', $defaultLocationOrder->pickup_latitude);
        $this->assertSame('44.3660731', $defaultLocationOrder->pickup_longitude);
        $this->assertSame('مخزن المتجر الأساسي', $defaultLocationOrder->pickup_location_label);

        $this->actingAs($merchant)
            ->post('/app/orders', $this->orderPayload([
                'customer_name_ar' => 'عميل الموقع المخصص',
                'pickup_latitude' => 33.9999999,
                'pickup_longitude' => 44.9999999,
                'pickup_location_label' => 'موقع مخصص لهذا الطلب',
            ]))
            ->assertRedirect();

        $savedLocationOrder = Order::withoutGlobalScopes()
            ->where('customer_name_ar', 'عميل الموقع المخصص')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('33.9999999', $savedLocationOrder->pickup_latitude);
        $this->assertSame('44.9999999', $savedLocationOrder->pickup_longitude);
        $this->assertSame('موقع مخصص لهذا الطلب', $savedLocationOrder->pickup_location_label);

        // Changing the shop point never changes the location snapshotted on
        // the already-created delivery.
        $merchant->update([
            'merchant_pickup_latitude' => 33.3200000,
            'merchant_pickup_longitude' => 44.3700000,
            'merchant_pickup_location_label' => 'الموقع الجديد',
        ]);
        $this->assertSame('33.9999999', $savedLocationOrder->fresh()->pickup_latitude);
        $this->assertSame('44.9999999', $savedLocationOrder->fresh()->pickup_longitude);

        $merchant->update([
            'merchant_pickup_latitude' => null,
            'merchant_pickup_longitude' => null,
            'merchant_pickup_location_label' => null,
            'merchant_pickup_location_updated_at' => null,
        ]);

        $this->actingAs($merchant)
            ->post('/app/orders', $this->orderPayload([
                'customer_name_ar' => 'عميل موقع النسخة القديمة',
                'pickup_latitude' => 33.3555555,
                'pickup_longitude' => 44.3888888,
                'pickup_location_label' => 'موقع طلب قديم صريح',
            ]))
            ->assertRedirect();

        $fallbackOrder = Order::withoutGlobalScopes()
            ->where('customer_name_ar', 'عميل موقع النسخة القديمة')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('33.3555555', $fallbackOrder->pickup_latitude);
        $this->assertSame('44.3888888', $fallbackOrder->pickup_longitude);
        $this->assertSame('موقع طلب قديم صريح', $fallbackOrder->pickup_location_label);
    }

    private function merchant(): User
    {
        return User::query()->where('username', 'تاجر')->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    private function profilePayload(User $user, array $overrides = []): array
    {
        return [
            'name' => $user->name,
            'phone' => $user->phone,
            'shop_name' => $user->shop_name,
            'address' => $user->address,
            'vehicle' => $user->vehicle,
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function orderPayload(array $overrides = []): array
    {
        return [
            'customer_name_ar' => 'عميل اختبار موقع التاجر',
            'phone' => '07710009999',
            'address_ar' => 'بغداد — الكرادة',
            'pickup_latitude' => 33.3152412,
            'pickup_longitude' => 44.3660731,
            'pickup_location_label' => 'موقع اختبار التاجر',
            'delivery_vehicle' => 'normal',
            'price' => 22000,
            ...$overrides,
        ];
    }
}
