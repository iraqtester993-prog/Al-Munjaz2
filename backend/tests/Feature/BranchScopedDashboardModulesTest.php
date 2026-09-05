<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminCourierLocationController;
use App\Http\Controllers\Admin\AdminPricingController;
use App\Http\Controllers\Admin\AdminReportsController;
use App\Models\Branch;
use App\Models\Order;
use App\Models\PricingRule;
use App\Models\Province;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchDashboardContext;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BranchScopedDashboardModulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::clear();

        // Isolated controller routes keep this suite focused on the
        // server-owned data boundary, independent of the dashboard route
        // registry and its separate capability middleware coverage.
        Route::get('/__tests/branch-dashboard/pricing', [AdminPricingController::class, 'index'])->middleware('web');
        Route::post('/__tests/branch-dashboard/pricing', [AdminPricingController::class, 'store'])->middleware('web');
        Route::put('/__tests/branch-dashboard/pricing/{pricingRule}', [AdminPricingController::class, 'update'])
            ->middleware(['web', SubstituteBindings::class]);
        Route::patch('/__tests/branch-dashboard/pricing/{pricingRule}/status', [AdminPricingController::class, 'status'])
            ->middleware(['web', SubstituteBindings::class]);
        Route::get('/__tests/branch-dashboard/reports', [AdminReportsController::class, 'index'])->middleware('web');
        Route::get('/__tests/branch-dashboard/courier-locations', [AdminCourierLocationController::class, 'index'])->middleware('web');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    public function test_branch_manager_pricing_is_owned_by_its_origin_governorate_and_local_merchants(): void
    {
        [$localBranch, $localProvince] = $this->branch('BGD-PRICE', 'بغداد', 1);
        [$foreignBranch, $foreignProvince] = $this->branch('BSR-PRICE', 'البصرة', 2);
        $manager = $this->manager($localBranch, 'pricing-branch-manager');
        $localMerchant = $this->user('merchant', 'pricing-local-merchant', $localBranch->id);
        $foreignMerchant = $this->user('merchant', 'pricing-foreign-merchant', $foreignBranch->id);

        $localGeneric = $this->pricingRule('تسعير بغداد العام', $localProvince->id);
        $localMerchantRule = $this->pricingRule('تسعير تاجر بغداد', $localProvince->id, $localMerchant->id);
        $foreignOrigin = $this->pricingRule('تسعير البصرة', $foreignProvince->id);
        $foreignMerchantRule = $this->pricingRule('تسعير تاجر أجنبي', $localProvince->id, $foreignMerchant->id);

        $response = $this->actingAs($manager)
            ->get('/__tests/branch-dashboard/pricing')
            ->assertOk();

        $ruleIds = collect($response->inertiaPage()['props']['rules'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $merchantIds = collect($response->inertiaPage()['props']['merchants'] ?? [])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertEqualsCanonicalizing([$localGeneric->id, $localMerchantRule->id], $ruleIds);
        $this->assertContains($localMerchant->id, $merchantIds);
        $this->assertNotContains($foreignMerchant->id, $merchantIds);
        $this->assertNotContains($foreignOrigin->id, $ruleIds);
        $this->assertNotContains($foreignMerchantRule->id, $ruleIds);

        $this->actingAs($manager)
            ->post('/__tests/branch-dashboard/pricing', $this->pricingPayload($foreignProvince->id))
            ->assertSessionHasErrors('origin_province_id');

        $this->actingAs($manager)
            ->post('/__tests/branch-dashboard/pricing', $this->pricingPayload($localProvince->id, $foreignMerchant->id))
            ->assertNotFound();

        $this->actingAs($manager)
            ->put('/__tests/branch-dashboard/pricing/'.$foreignOrigin->id, $this->pricingPayload($foreignProvince->id))
            ->assertNotFound();
    }

    public function test_branch_manager_reports_and_courier_locations_are_filtered_in_sql_to_the_primary_branch(): void
    {
        [$localBranch] = $this->branch('BGD-REPORT', 'بغداد', 1);
        [$foreignBranch] = $this->branch('BSR-REPORT', 'البصرة', 2);
        $manager = $this->manager($localBranch, 'reports-branch-manager');
        $localMerchant = $this->user('merchant', 'reports-local-merchant', $localBranch->id);
        $foreignMerchant = $this->user('merchant', 'reports-foreign-merchant', $foreignBranch->id);
        $localCourier = $this->user('courier', 'reports-local-courier', $localBranch->id, [
            'current_latitude' => 33.3152412,
            'current_longitude' => 44.3660731,
            'location_updated_at' => now(),
        ]);
        $foreignCourier = $this->user('courier', 'reports-foreign-courier', $foreignBranch->id, [
            'current_latitude' => 30.5085230,
            'current_longitude' => 47.7803960,
            'location_updated_at' => now(),
        ]);

        $localOrder = $this->order('REPORT-LOCAL', $localBranch, $localMerchant, $localCourier);
        $this->order('REPORT-FOREIGN', $foreignBranch, $foreignMerchant, $foreignCourier);

        $report = $this->actingAs($manager)
            ->get('/__tests/branch-dashboard/reports')
            ->assertOk();
        $reportProps = $report->inertiaPage()['props'];

        $this->assertSame(1, $reportProps['kpis']['orders']);
        $this->assertSame([$localBranch->id], collect($reportProps['branches'])->pluck('id')->all());
        $this->assertSame([$localCourier->id], collect($reportProps['couriers'])->pluck('id')->all());
        $this->assertSame([$localMerchant->id], collect($reportProps['merchants'])->pluck('id')->all());
        $this->assertNotContains($foreignBranch->id, collect($reportProps['branches'])->pluck('id')->all());

        $locations = $this->actingAs($manager)
            ->get('/__tests/branch-dashboard/courier-locations')
            ->assertOk();
        $locationIds = collect($locations->inertiaPage()['props']['couriers'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertSame([$localCourier->id], $locationIds);
        $this->assertNotContains($foreignCourier->id, $locationIds);
    }

    public function test_branch_manager_can_maintain_only_its_own_basic_branch_details(): void
    {
        [$localBranch, $localProvince] = $this->branch('BGD-BRANCH-CONTROL', 'بغداد', 1);
        [$foreignBranch, $foreignProvince] = $this->branch('BSR-BRANCH-CONTROL', 'البصرة', 2);
        $localBranch->update([
            'phone' => '07800000001',
            'email' => 'baghdad-branch@example.test',
            'address' => 'عنوان بغداد',
            'cash_balance' => 98_000,
        ]);
        $manager = $this->manager($localBranch, 'branch-control-manager');

        $props = $this->actingAs($manager)
            ->get('/dashboard/branches')
            ->assertOk()
            ->inertiaPage()['props'];

        $this->assertSame([$localBranch->id], collect($props['branches'])->pluck('id')->all());
        $this->assertTrue($props['canEditBranches']);
        $this->assertFalse($props['canCreateBranches']);
        $this->assertFalse($props['canChangeBranchStatus']);
        $this->assertFalse($props['canDeleteBranches']);
        $this->assertFalse($props['canManageBranchAccess']);
        $this->assertFalse($props['canViewBranchCashBalance']);
        $this->assertArrayNotHasKey('cash_balance', $props['branches'][0]);
        $this->assertArrayNotHasKey('access_accounts', $props['branches'][0]);
        $this->assertArrayNotHasKey('accessUsers', $props);

        // A route id is not a branch boundary. The controller fetches the
        // target again through the server-owned primary membership scope.
        $this->actingAs($manager)
            ->put('/dashboard/branches/'.$foreignBranch->id, [
                'name_ar' => 'محاولة تعديل فرع آخر',
            ])
            ->assertNotFound();

        // Only local contact details are writable. Browser-supplied routing
        // fields are ignored instead of allowing a manager to take over a
        // different governorate or network identity.
        $this->actingAs($manager)
            ->put('/dashboard/branches/'.$localBranch->id, [
                'name_ar' => 'فرع بغداد المحدّث',
                'phone' => '07800000009',
                'email' => 'BAGHDAD-UPDATED@EXAMPLE.TEST',
                'address' => 'عنوان بغداد المحدّث',
                'province_id' => $foreignProvince->id,
                'code' => 'TAKEOVER-ATTEMPT',
            ])
            ->assertRedirect();

        $localBranch->refresh();
        $this->assertSame('فرع بغداد المحدّث', $localBranch->name_ar);
        $this->assertSame('07800000009', $localBranch->phone);
        $this->assertSame('baghdad-updated@example.test', $localBranch->email);
        $this->assertSame('عنوان بغداد المحدّث', $localBranch->address);
        $this->assertSame($localProvince->id, (int) $localBranch->province_id);
        $this->assertSame('BGD-BRANCH-CONTROL', $localBranch->code);

        $this->actingAs($manager)
            ->post('/dashboard/branches', [
                'name_ar' => 'فرع غير مسموح',
                'province_id' => $foreignProvince->id,
            ])
            ->assertForbidden();
        $this->actingAs($manager)
            ->patch('/dashboard/branches/'.$localBranch->id.'/status', ['is_active' => false])
            ->assertForbidden();
        $this->actingAs($manager)
            ->delete('/dashboard/branches/'.$localBranch->id)
            ->assertForbidden();
        $this->actingAs($manager)
            ->post('/dashboard/branches/'.$localBranch->id.'/access', [])
            ->assertForbidden();
    }

    public function test_branch_manager_can_read_a_transfer_endpoint_but_cannot_mutate_an_order_outside_its_custody(): void
    {
        [$origin, $originProvince] = $this->branch('BGD-ORDER-SCOPE', 'بغداد', 1);
        [$destination, $destinationProvince] = $this->branch('BSR-ORDER-SCOPE', 'البصرة', 2);
        $manager = $this->manager($origin, 'order-scope-manager');
        $foreignMerchant = $this->user('merchant', 'order-scope-merchant', $destination->id);

        // The origin remains in the read route history, but the parcel has
        // already been received by the destination. A route endpoint must
        // not become authority to change customer data or COD.
        $receivedElsewhere = Order::withoutGlobalScopes()->create([
            'tenant_id' => Tenant::platform()->id,
            'track_no' => 'ORDER-SCOPE-RECEIVED',
            'source' => 'merchant',
            'customer_name_ar' => 'عميل الوجهة',
            'phone' => '07800000000',
            'address_ar' => 'عنوان الوجهة',
            'delivery_vehicle' => 'normal',
            'price' => 21_000,
            'fee' => 2_000,
            'status' => 'pending',
            'workflow_stage' => 'at_destination_branch',
            'branch_id' => $destination->id,
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination->id,
            'merchant_id' => $foreignMerchant->id,
            'province_id' => $destinationProvince->id,
            'date' => today(),
        ]);

        $this->actingAs($manager)
            ->put('/dashboard/orders/'.$receivedElsewhere->id, [
                'customer_name_ar' => 'محاولة تغيير بيانات عميل فرع آخر',
                'phone' => '07800000001',
                'address_ar' => 'محاولة تغيير العنوان',
                'delivery_vehicle' => 'normal',
                'price' => 1,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('orders', [
            'id' => $receivedElsewhere->id,
            'customer_name_ar' => 'عميل الوجهة',
            'price' => 21_000,
        ]);

        $localOrder = Order::withoutGlobalScopes()->create([
            'tenant_id' => Tenant::platform()->id,
            'track_no' => 'ORDER-SCOPE-LOCAL',
            'source' => 'merchant',
            'customer_name_ar' => 'عميل بغداد',
            'phone' => '07800000002',
            'address_ar' => 'عنوان بغداد',
            'delivery_vehicle' => 'normal',
            'price' => 22_000,
            'fee' => 2_000,
            'status' => 'pending',
            'workflow_stage' => 'at_origin_branch',
            'branch_id' => $origin->id,
            'origin_branch_id' => $origin->id,
            'merchant_id' => $this->user('merchant', 'order-scope-local-merchant', $origin->id)->id,
            'province_id' => $originProvince->id,
            'date' => today(),
        ]);

        $this->actingAs($manager)
            ->put('/dashboard/orders/'.$localOrder->id, [
                'customer_name_ar' => 'عميل بغداد المحدّث',
                'phone' => '07800000003',
                'address_ar' => 'عنوان بغداد المحدّث',
                'delivery_vehicle' => 'normal',
                'price' => 22_500,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('orders', [
            'id' => $localOrder->id,
            'customer_name_ar' => 'عميل بغداد المحدّث',
            'price' => 22_500,
        ]);
    }

    /** @return array{Branch, Province} */
    private function branch(string $code, string $provinceName, int $sortOrder): array
    {
        $province = Province::create([
            'name_ar' => $provinceName,
            'name_en' => $provinceName,
            'name_ku' => $provinceName,
            'sort_order' => $sortOrder,
            'is_active' => true,
        ]);
        $branch = Branch::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => Tenant::platform()->id,
            'is_platform_managed' => true,
            'code' => $code,
            'name_ar' => 'فرع '.$provinceName,
            'province_id' => $province->id,
            'is_active' => true,
        ]);

        return [$branch, $province];
    }

    private function manager(Branch $branch, string $username): User
    {
        $manager = $this->user('branch_manager', $username, $branch->id);
        app(BranchDashboardContext::class)->assignPrimaryMembership($manager, $branch);

        return $manager->fresh();
    }

    /** @param array<string, mixed> $overrides */
    private function user(string $role, string $username, ?int $branchId = null, array $overrides = []): User
    {
        return User::create([
            'tenant_id' => Tenant::platform()->id,
            'branch_id' => $branchId,
            'name' => $username,
            'username' => $username,
            'email' => $username.'@example.test',
            'phone' => '079'.str_pad((string) (10000000 + User::withoutGlobalScopes()->count()), 8, '0', STR_PAD_LEFT),
            'password' => 'StrongPassword123!',
            'role' => $role,
            'status' => 'active',
            'is_online' => true,
            ...$overrides,
        ]);
    }

    private function pricingRule(string $name, int $originProvinceId, ?int $merchantId = null): PricingRule
    {
        return PricingRule::withoutGlobalScopes()->create([
            'tenant_id' => Tenant::platform()->id,
            'name_ar' => $name,
            'merchant_id' => $merchantId,
            'origin_province_id' => $originProvinceId,
            'min_weight_grams' => 0,
            'base_fee' => 5000,
            'return_fee' => 0,
            'priority' => 100,
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function pricingPayload(int $originProvinceId, ?int $merchantId = null): array
    {
        return [
            'name_ar' => 'قاعدة اختبار',
            'merchant_id' => $merchantId,
            'origin_province_id' => $originProvinceId,
            'destination_province_id' => null,
            'min_weight_grams' => 0,
            'max_weight_grams' => null,
            'base_fee' => 5000,
            'return_fee' => 0,
            'priority' => 100,
        ];
    }

    private function order(string $trackNo, Branch $branch, User $merchant, User $courier): Order
    {
        return Order::withoutGlobalScopes()->create([
            'tenant_id' => Tenant::platform()->id,
            'track_no' => $trackNo,
            'source' => 'merchant',
            'customer_name_ar' => 'عميل اختبار',
            'phone' => '07800000000',
            'address_ar' => 'عنوان اختبار',
            'price' => 15000,
            'fee' => 2000,
            'status' => 'pending',
            'branch_id' => $branch->id,
            'origin_branch_id' => $branch->id,
            'merchant_id' => $merchant->id,
            'courier_id' => $courier->id,
            'province_id' => $branch->province_id,
            'date' => today(),
        ]);
    }
}
