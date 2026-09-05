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

class AdminOrderPaginationModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::clear();
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

    public function test_admin_can_show_every_matching_order_while_preserving_status_search_and_courier_filters(): void
    {
        [$admin, $merchant, $province] = $this->dashboardActors();
        $courier = $this->courier($merchant->tenant, 'courier-show-all', 'مندوب عرض الكل', '07940000051');
        $otherCourier = $this->courier($merchant->tenant, 'courier-show-all-other', 'مندوب آخر', '07940000052');
        $search = 'SHOW-ALL-MATCH';

        // More than the default page size proves this is not a normal 25-row
        // page. Every one of these rows must survive all three filters.
        for ($index = 1; $index <= 27; $index++) {
            $this->order($merchant, $province, "SHOW-ALL-{$index}", $search, [
                'courier_id' => $courier->id,
            ]);
        }

        // These share the text query but each fails one of the other filters.
        $this->order($merchant, $province, 'SHOW-ALL-OTHER-COURIER', $search, [
            'courier_id' => $otherCourier->id,
        ]);
        $this->order($merchant, $province, 'SHOW-ALL-OTHER-STATUS', $search, [
            'courier_id' => $courier->id,
            'status' => 'delivered',
            'workflow_stage' => 'delivered',
        ]);

        $response = $this->actingAs($admin)->get('/dashboard/orders?filter=pending&q='.$search.'&courier_id='.$courier->id.'&per_page=all&page=2');

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Orders')
                ->where('filter', 'pending')
                ->where('q', $search)
                ->where('courierId', $courier->id)
                ->where('perPage', 'all')
                ->has('orders.data', 27)
                ->where('orders.total', 27)
                ->where('orders.current_page', 1)
                ->where('orders.last_page', 1)
                ->where('orders.per_page', 27));

        $firstPageUrl = (string) $response->inertiaProps('orders.first_page_url');
        $this->assertStringContainsString('filter=pending', $firstPageUrl);
        $this->assertStringContainsString('q='.$search, $firstPageUrl);
        $this->assertStringContainsString('courier_id='.$courier->id, $firstPageUrl);
        $this->assertStringContainsString('per_page=all', $firstPageUrl);
    }

    public function test_admin_order_page_size_accepts_only_the_supported_numeric_modes_and_defaults_to_25(): void
    {
        [$admin, $merchant, $province] = $this->dashboardActors();
        $search = 'PAGE-SIZE-MODE';

        for ($index = 1; $index <= 27; $index++) {
            $this->order($merchant, $province, "PAGE-SIZE-{$index}", $search);
        }

        foreach ([
            ['requested' => null, 'expected' => 25],
            ['requested' => '50', 'expected' => 50],
            ['requested' => '100', 'expected' => 100],
        ] as $mode) {
            $suffix = $mode['requested'] === null ? '' : '&per_page='.$mode['requested'];
            $response = $this->actingAs($admin)->get('/dashboard/orders?q='.$search.$suffix);

            $response
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('perPage', $mode['expected'])
                    ->where('orders.per_page', $mode['expected']));
        }

        $this->actingAs($admin)
            ->from('/dashboard/orders')
            ->get('/dashboard/orders?per_page=200')
            ->assertRedirect('/dashboard/orders')
            ->assertSessionHasErrors('per_page');
    }

    /** @return array{0: User, 1: User, 2: Province} */
    private function dashboardActors(): array
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('username', 'تاجر')->firstOrFail();

        return [$admin, $merchant, $merchant->provinces()->firstOrFail()];
    }

    private function courier(Tenant $tenant, string $username, string $name, string $phone): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'username' => $username,
            'phone' => $phone,
            'password' => 'password',
            'role' => 'courier',
            'status' => 'active',
            'vehicle' => 'bike',
        ]);
    }

    private function order(User $merchant, Province $province, string $trackNo, string $customer, array $attributes = []): Order
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
        ], $attributes));
    }
}
