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

class MerchantPickupLocationVisibilityTest extends TestCase
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

    public function test_only_the_assigned_courier_receives_an_order_merchant_pickup_location(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $label = 'مخزن التاجر — شارع أبو نؤاس';

        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => 'ALM-PICKUP-VISIBILITY',
            'source' => 'merchant',
            'customer_name_ar' => 'عميل موقع الاستلام المحمي',
            'customer_name_en' => 'Protected pickup customer',
            'phone' => '07710000123',
            'address_ar' => 'بغداد — الكرادة',
            'address_en' => 'Baghdad — Karrada',
            'pickup_latitude' => 33.3152412,
            'pickup_longitude' => 44.3660731,
            'pickup_location_label' => $label,
            'delivery_vehicle' => 'bike',
            'price' => 25000,
            'fee' => 2500,
            'status' => 'approved',
            'workflow_stage' => 'awaiting_pickup',
            'courier_id' => $courier->id,
            'province_id' => $province->id,
            'date' => today(),
        ]);

        // The native/mobile API must provide the complete tuple both on the
        // orders collection and on the detail endpoint used after opening a
        // card. Coordinates are numeric so a client can send them directly
        // to the installed maps application without parsing a text address.
        Sanctum::actingAs($courier);

        $list = $this->getJson('/api/v1/orders')->assertOk();
        $listedOrder = collect($list->json('data'))->firstWhere('id', $order->id);
        $this->assertNotNull($listedOrder);
        $this->assertSame(33.3152412, data_get($listedOrder, 'pickup_latitude'));
        $this->assertSame(44.3660731, data_get($listedOrder, 'pickup_longitude'));
        $this->assertSame($label, data_get($listedOrder, 'pickup_location_label'));

        $this->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.pickup_latitude', 33.3152412)
            ->assertJsonPath('data.pickup_longitude', 44.3660731)
            ->assertJsonPath('data.pickup_location_label', $label);

        // The browser/PWA list exposes the authorised order, while its
        // on-demand sheet carries the precise pickup tuple. That avoids
        // sending private coordinates for every card on initial paint.
        $pwa = $this->actingAs($courier)->get('/app/orders?list=1')->assertOk();
        $pwaOrder = collect($pwa->inertiaProps('orders'))->firstWhere('id', $order->id);
        $this->assertNotNull($pwaOrder);

        $this->actingAs($courier)
            ->getJson("/app/orders?detail={$order->id}")
            ->assertOk()
            ->assertJsonPath('order.pickup_latitude', 33.3152412)
            ->assertJsonPath('order.pickup_longitude', 44.3660731)
            ->assertJsonPath('order.pickup_location_label', $label);

        $otherCourier = User::create([
            'tenant_id' => $courier->tenant_id,
            'name' => 'مندوب غير معيّن للموقع',
            'username' => 'unassigned-pickup-location-courier',
            'email' => 'unassigned-pickup-location-courier@example.test',
            'phone' => '07710000124',
            'password' => 'Password123!',
            'role' => 'courier',
            'status' => 'active',
        ]);
        $otherCourier->provinces()->syncWithoutDetaching([$province->id => ['is_primary' => true]]);

        Sanctum::actingAs($otherCourier);

        $forbidden = $this->getJson("/api/v1/orders/{$order->id}")->assertForbidden();
        $this->assertArrayNotHasKey('data', $forbidden->json());
        $this->assertStringNotContainsString($label, $forbidden->getContent());
        $this->assertStringNotContainsString('33.3152412', $forbidden->getContent());
        $this->assertStringNotContainsString('44.3660731', $forbidden->getContent());

        $unassignedList = $this->getJson('/api/v1/orders')->assertOk();
        $this->assertFalse(collect($unassignedList->json('data'))->contains('id', $order->id));
        $this->assertStringNotContainsString($label, $unassignedList->getContent());

        $unassignedPwa = $this->actingAs($otherCourier)->get('/app/orders?list=1')->assertOk();
        $this->assertFalse(collect($unassignedPwa->inertiaProps('orders'))->contains('id', $order->id));
        $this->assertStringNotContainsString($label, $unassignedPwa->getContent());
    }
}
