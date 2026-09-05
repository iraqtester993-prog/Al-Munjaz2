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

class OrderPhoneValidationTest extends TestCase
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

    public function test_pwa_order_creation_and_edit_require_an_eleven_digit_077_or_078_customer_phone(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();

        $this->actingAs($merchant)
            ->post('/app/orders', $this->pwaPayload(['phone' => '07910000000']))
            ->assertRedirect()
            ->assertSessionHasErrors('phone');

        $this->actingAs($merchant)
            ->post('/app/orders', $this->pwaPayload(['phone' => '0771000000']))
            ->assertRedirect()
            ->assertSessionHasErrors('phone');

        $this->actingAs($merchant)
            ->post('/app/orders', $this->pwaPayload(['phone2' => '0781000000']))
            ->assertRedirect()
            ->assertSessionHasErrors('phone2');

        $this->actingAs($merchant)
            ->post('/app/orders', $this->pwaPayload())
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors(['phone', 'phone2']);

        $order = Order::withoutGlobalScopes()
            ->where('customer_name_ar', 'عميل فحص الهاتف')
            ->latest('id')
            ->firstOrFail();

        $this->actingAs($merchant)
            ->post(route('app.orders.update', $order), $this->pwaPayload(['phone' => '0781000000']))
            ->assertRedirect()
            ->assertSessionHasErrors('phone');

        $admin = User::where('role', 'admin')->firstOrFail();
        $this->actingAs($admin)
            ->put(route('admin.orders.update', $order), $this->pwaPayload(['phone' => '07910000000']))
            ->assertRedirect()
            ->assertSessionHasErrors('phone');

        $this->actingAs($admin)
            ->put(route('admin.orders.update', $order), $this->pwaPayload(['phone2' => '0781000000']))
            ->assertRedirect()
            ->assertSessionHasErrors('phone2');

        $this->assertSame('07710000001', $order->fresh()->phone);
    }

    public function test_token_api_order_creation_enforces_the_same_customer_phone_format(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        Sanctum::actingAs($merchant);

        $payload = [
            'customer_name_ar' => 'عميل واجهة الهاتف',
            'phone' => '07910000000',
            'address_ar' => 'بغداد — الكرادة',
            'pickup_latitude' => 33.3152412,
            'pickup_longitude' => 44.3660731,
            'pickup_location_label' => 'متجر الاختبار — الكرادة',
            'delivery_vehicle' => 'normal',
            'price' => 25000,
            'province_id' => $province->id,
        ];

        $this->postJson('/api/v1/orders', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->postJson('/api/v1/orders', [...$payload, 'phone' => '07710000001', 'phone2' => '0781000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone2');
    }

    /** @return array<string, mixed> */
    private function pwaPayload(array $overrides = []): array
    {
        return [
            'customer_name_ar' => 'عميل فحص الهاتف',
            'phone' => '07710000001',
            'phone2' => '07810000002',
            'address_ar' => 'بغداد — الكرادة',
            'pickup_latitude' => 33.3152412,
            'pickup_longitude' => 44.3660731,
            'pickup_location_label' => 'متجر الاختبار — الكرادة',
            'delivery_vehicle' => 'normal',
            'price' => 25000,
            ...$overrides,
        ];
    }
}
