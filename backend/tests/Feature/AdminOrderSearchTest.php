<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Province;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminOrderSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::clear();
        // These controller assertions render an Inertia page. Disable the
        // Vite lookup so the test does not depend on generated build files.
        $this->withoutVite();
        $this->seed(PlanSeeder::class);
        $this->seed(ProvinceSeeder::class);
        $this->seed(DemoSeeder::class);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    public function test_admin_order_search_matches_customer_tracking_number_and_any_assigned_courier(): void
    {
        [$admin, $merchant, $province] = $this->dashboardActors();
        $courier = $this->courier($merchant->tenant, 'courier-search', 'سعد مندوب البحث', '07930000055', 'active');

        $customerMatch = $this->order($merchant, $province, 'ORD-CUSTOMER-SEARCH', 'عميل الاسم الفريد', [
            'courier_id' => $courier->id,
        ]);
        $trackingMatch = $this->order($merchant, $province, 'ORD-TRACKING-SEARCH', 'عميل آخر');
        $pickupMatch = $this->order($merchant, $province, 'ORD-PICKUP-SEARCH', 'عميل الاستلام', [
            'pickup_courier_id' => $courier->id,
        ]);

        $this->inertiaGet($admin, '/dashboard/orders?q='.urlencode('الاسم الفريد'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Orders')
                ->where('q', 'الاسم الفريد')
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $customerMatch->id));

        $this->inertiaGet($admin, '/dashboard/orders?q=ORD-TRACKING-SEARCH')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $trackingMatch->id));

        // Courier text lookup covers every operational assignment relation,
        // not just the legacy direct courier column.
        $this->inertiaGet($admin, '/dashboard/orders?q=07930000055')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 2)
                ->where('orders.data.0.id', $pickupMatch->id)
                ->where('orders.data.1.id', $customerMatch->id));
    }

    public function test_admin_can_filter_to_one_active_or_disabled_courier_without_widening_access(): void
    {
        [$admin, $merchant, $province] = $this->dashboardActors();
        $suspendedCourier = $this->courier($merchant->tenant, 'courier-history', 'مندوب السجل', '07930000056', 'suspended');
        $otherCourier = $this->courier($merchant->tenant, 'courier-other', 'مندوب آخر', '07930000057', 'active');

        $direct = $this->order($merchant, $province, 'ORD-HISTORY-DIRECT', 'عميل مباشر', [
            'courier_id' => $suspendedCourier->id,
        ]);
        $pickup = $this->order($merchant, $province, 'ORD-HISTORY-PICKUP', 'عميل استلام', [
            'pickup_courier_id' => $suspendedCourier->id,
        ]);
        $delivery = $this->order($merchant, $province, 'ORD-HISTORY-DELIVERY', 'عميل توصيل', [
            'delivery_courier_id' => $suspendedCourier->id,
        ]);
        $this->order($merchant, $province, 'ORD-OTHER-COURIER', 'عميل آخر', [
            'courier_id' => $otherCourier->id,
        ]);

        $this->inertiaGet($admin, '/dashboard/orders?courier_id='.$suspendedCourier->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Orders')
                ->where('courierId', $suspendedCourier->id)
                ->has('orders.data', 3)
                ->where('orders.data.0.id', $delivery->id)
                ->where('orders.data.1.id', $pickup->id)
                ->where('orders.data.2.id', $direct->id)
                ->where('courierFilters', fn ($couriers) => collect($couriers)->contains(
                    fn (array $courier) => $courier['id'] === $suspendedCourier->id
                        && $courier['status'] === 'suspended'
                )));

        // The platform-wide operations route remains an admin-only boundary;
        // branch users must use their separately scoped branch portal.
        $owner = User::create([
            'tenant_id' => $merchant->tenant_id,
            'name' => 'مالك فرع الاختبار',
            'username' => 'branch-owner-search',
            'phone' => '07930000058',
            'password' => 'password',
            'role' => 'owner',
            'status' => 'active',
        ]);

        $this->actingAs($owner)
            ->get('/dashboard/orders?courier_id='.$suspendedCourier->id)
            ->assertRedirect();
    }

    public function test_courier_filter_rejects_non_courier_and_soft_deleted_accounts(): void
    {
        [$admin, $merchant] = $this->dashboardActors();
        $deletedCourier = $this->courier($merchant->tenant, 'courier-deleted-search', 'مندوب محذوف', '07930000059', 'suspended');
        $deletedCourier->delete();

        $this->actingAs($admin)
            ->from('/dashboard/orders')
            ->get('/dashboard/orders?courier_id='.$merchant->id)
            ->assertRedirect('/dashboard/orders')
            ->assertSessionHasErrors('courier_id');

        $this->actingAs($admin)
            ->from('/dashboard/orders')
            ->get('/dashboard/orders?courier_id='.$deletedCourier->id)
            ->assertRedirect('/dashboard/orders')
            ->assertSessionHasErrors('courier_id');
    }

    /**
     * @return array{0: User, 1: User, 2: Province}
     */
    private function dashboardActors(): array
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('username', 'تاجر')->firstOrFail();

        return [$admin, $merchant, $merchant->provinces()->firstOrFail()];
    }

    private function courier(Tenant $tenant, string $username, string $name, string $phone, string $status): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'username' => $username,
            'phone' => $phone,
            'password' => 'password',
            'role' => 'courier',
            'status' => $status,
            'vehicle' => 'bike',
        ]);
    }

    private function order(User $merchant, Province $province, string $trackNo, string $customer, array $assignments = []): Order
    {
        return Order::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => $trackNo,
            'source' => 'merchant',
            'customer_name_ar' => $customer,
            'customer_name_en' => $customer,
            'phone' => '078'.str_pad((string) random_int(1, 9_999_999), 7, '0', STR_PAD_LEFT),
            'address_ar' => 'بغداد — الكرادة',
            'address_en' => 'Baghdad — Karrada',
            'delivery_vehicle' => 'normal',
            'price' => 25000,
            'fee' => 2500,
            'status' => 'pending',
            'workflow_stage' => 'created',
            'province_id' => $province->id,
            'date' => today(),
        ], $assignments));
    }

    private function inertiaGet(User $user, string $url)
    {
        return $this->actingAs($user)->get($url);
    }
}
