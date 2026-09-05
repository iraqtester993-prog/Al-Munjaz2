<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Province;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourierAppOrderLifecycleTest extends TestCase
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

    public function test_an_on_duty_direct_courier_can_claim_then_complete_a_delivery_through_the_app_routes(): void
    {
        [$merchant, $courier, $province] = $this->readyActors();
        $order = $this->pendingOrder($merchant, $province, 'ALM-APP-LIFECYCLE-DELIVERY');

        $this->actingAs($courier)
            ->post(route('app.orders.claim', $order))
            ->assertRedirect()
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('approved', $order->status);
        $this->assertSame($courier->id, $order->courier_id);

        $this->actingAs($courier)
            ->post(route('app.orders.status', $order), ['status' => 'courier'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('courier', $order->fresh()->status);

        $this->actingAs($courier)
            ->post(route('app.orders.status', $order), ['status' => 'delivered'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertNotNull($order->accepted_at);
        $this->assertNotNull($order->picked_at);
        $this->assertNotNull($order->delivered_at);
    }

    public function test_an_on_duty_direct_courier_can_start_and_confirm_a_return_through_the_app_routes(): void
    {
        [$merchant, $courier, $province] = $this->readyActors();
        $order = $this->pendingOrder($merchant, $province, 'ALM-APP-LIFECYCLE-RETURN');
        $courier->update(['admin_deduction_per_order' => 2_000]);
        $courier = $courier->fresh();
        $startingQiBalance = (int) $courier->wallet->balance;

        $this->actingAs($courier)->post(route('app.orders.claim', $order))->assertRedirect();
        $this->actingAs($courier)->post(route('app.orders.status', $order), ['status' => 'courier'])->assertRedirect();
        $adminDeduction = (int) $order->fresh()->admin_deduction_applied;
        $this->assertSame($startingQiBalance - $adminDeduction, (int) $courier->wallet->fresh()->balance);

        $this->actingAs($courier)
            ->post(route('app.orders.return', $order), [
                'fee_mode' => 'none',
                'return_reason' => 'العميل اعتذر عن استلام الطلب.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('returned', $order->status);
        $this->assertSame('return_pending_merchant', $order->workflow_stage);
        $this->assertNotNull($order->returned_at);
        $this->assertSame('none', $order->return_fee_mode);
        $this->assertSame('العميل اعتذر عن استلام الطلب.', $order->return_reason);
        $this->assertSame($startingQiBalance, (int) $courier->wallet->fresh()->balance);
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'user_id' => $courier->id,
            'type' => 'commission_refund',
            'amount' => $adminDeduction,
            'direction' => 1,
        ]);

        // A returned order remains visible in its own queue and normal detail
        // endpoint until the courier explicitly archives it or the nightly
        // archive task processes it.
        $returnedList = $this->actingAs($courier)
            ->getJson('/app/orders?filter=returned&list=1');

        $returnedList
            ->assertOk()
            ->assertJsonFragment(['id' => $order->id]);
        $this->assertGreaterThanOrEqual(1, (int) $returnedList->json('counts.returned'));
        $this->actingAs($courier)
            ->getJson("/app/orders?detail={$order->id}")
            ->assertOk()
            ->assertJsonPath('order.return_reason', 'العميل اعتذر عن استلام الطلب.');

        $this->actingAs($courier)
            ->post(route('app.orders.return-to-merchant', $order))
            ->assertRedirect()
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('returned', $order->status);
        $this->assertSame('returned_to_merchant', $order->workflow_stage);
        $this->assertNotNull($order->returned_to_merchant_at);
    }

    public function test_claim_preconditions_are_returned_as_displayable_inertia_field_errors(): void
    {
        [$merchant, $courier, $province] = $this->readyActors();
        $order = $this->pendingOrder($merchant, $province, 'ALM-APP-LIFECYCLE-GUARDS');

        $courier->update(['is_online' => false]);
        $this->actingAs($courier)
            ->get('/app/orders?filter=pending&list=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/Orders')
                ->where('isCourier', true)
                ->where('onDuty', false));

        $this->actingAs($courier)
            ->post(route('app.orders.claim', $order))
            ->assertRedirect()
            ->assertSessionHasErrors([
                'order' => 'لا يمكنك أخذ الطلب الآن لأن زر «أنا متاح للعمل» غير مفعّل. فعّله من الصفحة الرئيسية، ثم حاول مرة أخرى.',
            ]);

        $courier->update([
            'is_online' => true,
            'current_latitude' => null,
            'current_longitude' => null,
            'location_updated_at' => null,
        ]);
        $this->actingAs($courier)
            ->post(route('app.orders.claim', $order))
            ->assertRedirect()
            ->assertSessionHasErrors('location');

        $courier->update([
            'current_latitude' => 33.3152412,
            'current_longitude' => 44.3660731,
            'location_updated_at' => now(),
        ]);
        $courier->wallet()->firstOrFail()->update(['budget_balance' => 1]);
        $this->actingAs($courier)
            ->post(route('app.orders.claim', $order))
            ->assertRedirect()
            ->assertSessionHasErrors([
                'order' => 'رصيد ميزانية المندوب لا يغطي سعر الطلب دون أجرة التوصيل.',
            ]);

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->courier_id);
    }

    public function test_stale_status_actions_return_order_field_errors_in_the_pwa_and_token_api(): void
    {
        [$merchant, $courier, $province] = $this->readyActors();
        $order = $this->pendingOrder($merchant, $province, 'ALM-APP-LIFECYCLE-STALE');

        $this->actingAs($courier)->post(route('app.orders.claim', $order))->assertRedirect();

        // A delivery cannot be confirmed before the courier has recorded that
        // the parcel was collected. The PWA receives a regular form error.
        $this->actingAs($courier)
            ->post(route('app.orders.status', $order), ['status' => 'delivered'])
            ->assertRedirect()
            ->assertSessionHasErrors([
                'order' => 'انتقال حالة الطلب غير مسموح.',
            ]);

        $this->assertSame('approved', $order->fresh()->status);

        // The API must expose the same reason in its JSON validation payload.
        Sanctum::actingAs($courier);
        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order')
            ->assertJsonPath('errors.order.0', 'انتقال حالة الطلب غير مسموح.');
    }

    /** @return array{0: User, 1: User, 2: Province} */
    private function readyActors(): array
    {
        $merchant = User::query()->where('username', 'تاجر')->firstOrFail();
        $courier = User::query()->where('username', 'مندوب')->firstOrFail();
        $province = $merchant->provinces()->active()->firstOrFail();

        // These are the normal operational prerequisites for a real claim.
        // Explicitly satisfy them here so the test detects a controller or
        // authorisation regression instead of intentionally testing a guard.
        $courier->update([
            'is_online' => true,
            'current_latitude' => 33.3152412,
            'current_longitude' => 44.3660731,
            'location_updated_at' => now(),
        ]);

        return [$merchant, $courier->fresh(), $province];
    }

    private function pendingOrder(User $merchant, Province $province, string $trackNo): Order
    {
        return Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => $trackNo,
            'source' => 'merchant',
            'customer_name_ar' => 'عميل دورة التطبيق',
            'customer_name_en' => 'App workflow customer',
            'phone' => '077'.str_pad((string) random_int(1, 9_999_999), 8, '0', STR_PAD_LEFT),
            'address_ar' => 'بغداد — الكرادة',
            'address_en' => 'Baghdad — Karrada',
            'pickup_latitude' => 33.3152412,
            'pickup_longitude' => 44.3660731,
            'pickup_location_label' => 'متجر الاختبار',
            'delivery_vehicle' => 'normal',
            'price' => 25_000,
            'fee' => 3_000,
            'return_fee' => 3_000,
            'status' => 'pending',
            'workflow_stage' => 'created',
            'province_id' => $province->id,
            'pickup_deadline_at' => now()->addMinutes(30),
            'date' => today(),
        ]);
    }
}
