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

class CourierOrderArchiveTest extends TestCase
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

    public function test_courier_can_manually_archive_delivered_and_returned_orders(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $delivered = $this->order($merchant, $courier, $province->id, 'ALM-ARCHIVE-DELIVERED', 'delivered');
        $returned = $this->order($merchant, $courier, $province->id, 'ALM-ARCHIVE-RETURNED', 'returned');

        foreach ([$delivered, $returned] as $order) {
            // Terminal work remains active until a permitted actor explicitly archives it.
            $this->actingAs($courier)
                ->getJson("/app/orders?detail={$order->id}")
                ->assertOk()
                ->assertJsonPath('order.id', $order->id)
                ->assertJsonPath('order.can_archive', true);

            $this->actingAs($courier)
                ->getJson("/app/orders?detail={$order->id}&archive=1")
                ->assertNotFound();

            $this->actingAs($courier)
                ->post(route('app.orders.archive', $order))
                ->assertRedirect();

            $this->assertNotNull($order->fresh()->archived_at);
            $this->assertDatabaseHas('activity_logs', [
                'user_id' => $courier->id,
                'action' => 'order.archived_by_courier',
                'subject_id' => $order->id,
            ]);

            $this->actingAs($courier)
                ->getJson("/app/orders?detail={$order->id}")
                ->assertNotFound();

            $this->actingAs($courier)
                ->getJson("/app/orders?detail={$order->id}&archive=1")
                ->assertOk()
                ->assertJsonPath('order.id', $order->id)
                ->assertJsonPath('order.status', $order->status)
                ->assertJsonPath('order.can_archive', false);
        }

        $expectedReturned = Order::withoutGlobalScopes()
            ->where('tenant_id', $merchant->tenant_id)
            ->where('courier_id', $courier->id)
            ->where('status', 'returned')
            ->whereNotNull('archived_at')
            ->count();

        $this->actingAs($courier)
            ->get('/app/reports?detail_status=returned')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Mobile/Reports')
                ->where('summary.returned_count', $expectedReturned)
                ->has('orders', $expectedReturned));
    }

    public function test_courier_cannot_manually_archive_non_final_statuses(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();

        foreach (['pending', 'approved', 'courier', 'cancelled', 'damaged', 'rejected'] as $status) {
            $order = $this->order(
                $merchant,
                $courier,
                $province->id,
                'ALM-ARCHIVE-BLOCKED-'.strtoupper($status),
                $status,
            );

            $this->actingAs($courier)
                ->post(route('app.orders.archive', $order))
                ->assertStatus(422);

            $this->assertNull($order->fresh()->archived_at);
        }
    }

    public function test_merchant_can_manually_archive_its_delivered_and_returned_orders(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $delivered = $this->order($merchant, $courier, $province->id, 'ALM-MERCHANT-ARCHIVE-DELIVERED', 'delivered');
        $returned = $this->order($merchant, $courier, $province->id, 'ALM-MERCHANT-ARCHIVE-RETURNED', 'returned');
        // Historic records created before the direct-merchant relation was
        // introduced are still owned by their `created_by` account.
        $returned->forceFill(['merchant_id' => null])->save();

        foreach ([$delivered, $returned] as $order) {
            $this->actingAs($merchant)
                ->getJson("/app/orders?detail={$order->id}")
                ->assertOk()
                ->assertJsonPath('order.id', $order->id)
                ->assertJsonPath('order.can_archive', true);

            $this->actingAs($merchant)
                ->post(route('app.orders.archive', $order))
                ->assertRedirect();

            $this->assertNotNull($order->fresh()->archived_at);
            $this->assertDatabaseHas('activity_logs', [
                'user_id' => $merchant->id,
                'action' => 'order.archived_by_merchant',
                'subject_id' => $order->id,
            ]);

            $this->actingAs($merchant)
                ->getJson("/app/orders?detail={$order->id}")
                ->assertNotFound();

            $this->actingAs($merchant)
                ->getJson("/app/orders?detail={$order->id}&archive=1")
                ->assertOk()
                ->assertJsonPath('order.id', $order->id)
                ->assertJsonPath('order.can_archive', false);
        }
    }

    public function test_merchant_cannot_archive_another_merchants_final_order_in_the_same_tenant(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $otherMerchant = User::create([
            'tenant_id' => $merchant->tenant_id,
            'name' => 'تاجر أرشفة آخر',
            'username' => 'merchant-archive-guard',
            'phone' => '07819990077',
            'password' => 'Password123!',
            'role' => 'merchant',
            'status' => 'active',
            'shop_name' => 'متجر أرشفة آخر',
            'address' => 'بغداد — الكرادة',
        ]);
        $otherMerchant->provinces()->syncWithoutDetaching([
            $province->id => ['is_primary' => true],
        ]);
        $order = $this->order($otherMerchant, $courier, $province->id, 'ALM-MERCHANT-ARCHIVE-GUARD', 'delivered');

        $this->actingAs($merchant)
            ->getJson("/app/orders?detail={$order->id}")
            ->assertOk()
            ->assertJsonPath('order.can_archive', false);

        $this->actingAs($merchant)
            ->post(route('app.orders.archive', $order))
            ->assertForbidden();

        $this->assertNull($order->fresh()->archived_at);
    }

    private function order(User $merchant, User $courier, int $provinceId, string $trackNo, string $status): Order
    {
        $stage = match ($status) {
            'pending' => 'created',
            'approved' => 'awaiting_pickup',
            'courier' => 'picked_up',
            'delivered' => 'delivered',
            'returned' => 'returned',
            default => $status,
        };

        return Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'courier_id' => $courier->id,
            'track_no' => $trackNo,
            'source' => 'merchant',
            'customer_name_ar' => 'عميل الأرشفة',
            'customer_name_en' => 'Archive customer',
            'phone' => '07700000111',
            'address_ar' => 'بغداد — الكرادة',
            'address_en' => 'Baghdad — Karrada',
            'delivery_vehicle' => 'normal',
            'price' => 25_000,
            'fee' => 3_000,
            'status' => $status,
            'workflow_stage' => $stage,
            'delivered_at' => $status === 'delivered' ? now()->subMinute() : null,
            'returned_at' => $status === 'returned' ? now()->subMinute() : null,
            'province_id' => $provinceId,
            'date' => today(),
        ]);
    }
}
