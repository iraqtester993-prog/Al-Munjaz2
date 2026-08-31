<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\CourierOrderAccess;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepublishOrderTest extends TestCase
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

    public function test_merchant_can_republish_an_expired_unclaimed_order(): void
    {
        $merchant = User::query()->where('username', 'تاجر')->firstOrFail();
        $order = Order::withoutGlobalScopes()
            ->where('tenant_id', $merchant->tenant_id)
            ->where('status', 'pending')
            ->whereNull('courier_id')
            ->firstOrFail();

        $order->forceFill(['pickup_deadline_at' => now()->subMinute()])->save();
        Setting::set('order_expiry_minutes', 45);

        $this->actingAs($merchant)
            ->post(route('app.orders.republish', $order))
            ->assertRedirect();

        $order->refresh();

        $this->assertSame('pending', $order->status);
        $this->assertNull($order->courier_id);
        $this->assertNotNull($order->pickup_deadline_at);
        $this->assertTrue($order->pickup_deadline_at->greaterThan(now()->addMinutes(44)));
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'order.republished',
            'subject_id' => $order->id,
        ]);

        $courier = User::query()->where('username', 'مندوب')->firstOrFail();
        $this->assertTrue(
            app(CourierOrderAccess::class)->available($courier)->whereKey($order->id)->exists(),
        );
    }

    public function test_merchant_cannot_republish_before_the_offer_window_expires(): void
    {
        $merchant = User::query()->where('username', 'تاجر')->firstOrFail();
        $order = Order::withoutGlobalScopes()
            ->where('tenant_id', $merchant->tenant_id)
            ->where('status', 'pending')
            ->whereNull('courier_id')
            ->firstOrFail();

        $order->forceFill(['pickup_deadline_at' => now()->addMinute()])->save();

        $this->actingAs($merchant)
            ->post(route('app.orders.republish', $order))
            ->assertStatus(422);

        $this->assertTrue($order->fresh()->pickup_deadline_at->greaterThan(now()));
    }
}
