<?php

namespace Tests\Feature;

use App\Models\FinanceRequest;
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

class AdminDirectCourierOperationsTest extends TestCase
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

    public function test_dashboard_kpis_reports_and_finance_directory_use_only_direct_couriers(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('role', 'merchant')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $courierTenant = User::where('role', 'courier')->firstOrFail()->tenant;

        $directCourier = $this->operationalUser($courierTenant, $province, 'courier', 'direct-operations-courier', '07930000011', true);
        $pickupCourier = $this->operationalUser($courierTenant, $province, 'pickup_courier', 'legacy-pickup-courier', '07930000012', true);
        $deliveryCourier = $this->operationalUser($courierTenant, $province, 'delivery_courier', 'legacy-delivery-courier', '07930000013', true);
        $transporter = $this->operationalUser($courierTenant, $province, 'transporter', 'branch-transporter', '07930000014', true);

        $this->order($merchant, $province, 'ALM-DIRECT-REPORT', 'delivered', $directCourier->id);
        $this->order($merchant, $province, 'ALM-LEGACY-REPORT', 'delivered', null, $pickupCourier->id, $deliveryCourier->id);

        $expectedCourierCount = User::withoutGlobalScopes()->where('role', 'courier')->count();
        $expectedOnlineCourierCount = User::withoutGlobalScopes()
            ->where('role', 'courier')
            ->where('status', 'active')
            ->where('is_online', true)
            ->count();

        $dashboard = $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard')
                ->where('kpis.couriers', $expectedCourierCount)
                ->where('operations.onlineCouriers', $expectedOnlineCourierCount));

        $report = $this->actingAs($admin)
            ->get('/dashboard/reports?from='.today()->toDateString().'&to='.today()->toDateString())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Reports'));

        $reportCouriers = collect($report->inertiaProps('couriers'));
        $directRow = $reportCouriers->firstWhere('id', $directCourier->id);

        $this->assertNotNull($directRow);
        $this->assertSame(1, (int) $directRow['orders']);
        $this->assertSame(1, (int) $directRow['delivered']);
        $this->assertFalse($reportCouriers->contains('id', $pickupCourier->id));
        $this->assertFalse($reportCouriers->contains('id', $deliveryCourier->id));
        $this->assertFalse($reportCouriers->contains('id', $transporter->id));

        $finance = $this->actingAs($admin)
            ->get('/dashboard/finance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Finance'));

        $financeAccounts = collect($finance->inertiaProps('accounts'));
        $this->assertTrue($financeAccounts->contains('id', $directCourier->id));
        $this->assertFalse($financeAccounts->contains('id', $pickupCourier->id));
        $this->assertFalse($financeAccounts->contains('id', $deliveryCourier->id));
        $this->assertFalse($financeAccounts->contains('id', $transporter->id));

        $before = FinanceRequest::withoutGlobalScopes()->count();
        $this->actingAs($admin)
            ->post('/dashboard/finance/settlements', [
                'user_id' => $pickupCourier->id,
                'type' => FinanceRequest::BUDGET_RECHARGE,
                'amount' => 1000,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('user_id');
        $this->assertSame($before, FinanceRequest::withoutGlobalScopes()->count());
    }

    private function operationalUser(Tenant $tenant, Province $province, string $role, string $username, string $phone, bool $online): User
    {
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $username,
            'username' => $username,
            'phone' => $phone,
            'password' => 'Password123!',
            'role' => $role,
            'status' => 'active',
            'vehicle' => 'bike',
            'is_online' => $online,
        ]);
        $user->provinces()->syncWithoutDetaching([$province->id => ['is_primary' => true]]);

        return $user;
    }

    private function order(User $merchant, Province $province, string $trackNo, string $status, ?int $courierId = null, ?int $pickupCourierId = null, ?int $deliveryCourierId = null): Order
    {
        return Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => $trackNo,
            'source' => 'merchant',
            'customer_name_ar' => 'عميل اختبار',
            'customer_name_en' => 'Test customer',
            'phone' => '078'.str_pad((string) random_int(1, 9_999_999), 7, '0', STR_PAD_LEFT),
            'address_ar' => 'بغداد — الكرادة',
            'address_en' => 'Baghdad — Karrada',
            'delivery_vehicle' => 'normal',
            'price' => 25000,
            'fee' => 2500,
            'status' => $status,
            'workflow_stage' => $status,
            'courier_id' => $courierId,
            'pickup_courier_id' => $pickupCourierId,
            'delivery_courier_id' => $deliveryCourierId,
            'province_id' => $province->id,
            'date' => today(),
            'delivered_at' => $status === 'delivered' ? now() : null,
        ]);
    }
}
