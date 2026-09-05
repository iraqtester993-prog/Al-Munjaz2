<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminCashboxController;
use App\Http\Controllers\Admin\AdminCourierLocationController;
use App\Http\Controllers\Admin\AdminEmployeeController;
use App\Http\Controllers\Admin\AdminFinanceController;
use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\AdminOrderController;
use App\Http\Controllers\Admin\AdminPermissionProfileController;
use App\Http\Controllers\Admin\AdminPricingController;
use App\Http\Controllers\Admin\AdminReportsController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\BranchController;
use App\Models\Branch;
use App\Models\Cashbox;
use App\Models\CashboxVoucher;
use App\Models\DashboardPermissionProfile;
use App\Models\FinanceRequest;
use App\Models\Order;
use App\Models\PricingRule;
use App\Models\Province;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BranchDashboardContext;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SuperAdminBranchDashboardFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::clear();
        $this->withoutVite();

        // These controller routes isolate the filter contract from unrelated
        // route-capability coverage. The controllers still resolve the same
        // authenticated BranchDashboardScope used by the real dashboard.
        Route::get('/__tests/dashboard-branch-filter/orders', [AdminOrderController::class, 'index'])->middleware('web');
        Route::get('/__tests/dashboard-branch-filter/merchants', [AdminUserController::class, 'merchants'])->middleware('web');
        Route::get('/__tests/dashboard-branch-filter/couriers', [AdminUserController::class, 'couriers'])->middleware('web');
        Route::get('/__tests/dashboard-branch-filter/courier-locations', [AdminCourierLocationController::class, 'index'])->middleware('web');
        Route::get('/__tests/dashboard-branch-filter/finance', [AdminFinanceController::class, 'index'])->middleware('web');
        Route::get('/__tests/dashboard-branch-filter/cashboxes', [AdminCashboxController::class, 'index'])->middleware('web');
        Route::get('/__tests/dashboard-branch-filter/pricing', [AdminPricingController::class, 'index'])->middleware('web');
        Route::get('/__tests/dashboard-branch-filter/reports', [AdminReportsController::class, 'index'])->middleware('web');
        Route::get('/__tests/dashboard-branch-filter/notifications', [AdminNotificationController::class, 'index'])->middleware('web');
        Route::get('/__tests/dashboard-branch-filter/branches', [BranchController::class, 'index'])->middleware('web');
        Route::get('/__tests/dashboard-branch-filter/employees', [AdminEmployeeController::class, 'index'])->middleware('web');
        Route::post('/__tests/dashboard-branch-filter/employees', [AdminEmployeeController::class, 'store'])->middleware('web');
        Route::put('/__tests/dashboard-branch-filter/employees/{user}', [AdminEmployeeController::class, 'update'])->middleware('web');
        Route::get('/__tests/dashboard-branch-filter/permissions', [AdminPermissionProfileController::class, 'index'])->middleware('web');
        Route::post('/__tests/dashboard-branch-filter/permissions', [AdminPermissionProfileController::class, 'store'])->middleware('web');
        Route::put('/__tests/dashboard-branch-filter/permissions/{permissionProfile}', [AdminPermissionProfileController::class, 'update'])->middleware('web');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    public function test_super_admin_filters_orders_rosters_and_locations_by_an_active_or_disabled_branch(): void
    {
        [$activeBranch, $activeProvince] = $this->branch('BGD-FILTER', 'بغداد', true);
        [$disabledBranch, $disabledProvince] = $this->branch('BSR-FILTER', 'البصرة', false);
        $superAdmin = $this->superAdmin('super-branch-filter');

        $activeMerchant = $this->user('merchant', 'active-filter-merchant', $activeBranch->id);
        $disabledMerchant = $this->user('merchant', 'disabled-filter-merchant', $disabledBranch->id);
        $activeCourier = $this->user('courier', 'active-filter-courier', $activeBranch->id, [
            'current_latitude' => 33.3152412,
            'current_longitude' => 44.3660731,
            'location_updated_at' => now(),
        ]);
        $disabledCourier = $this->user('courier', 'disabled-filter-courier', $disabledBranch->id, [
            'current_latitude' => 30.5085230,
            'current_longitude' => 47.7803960,
            'location_updated_at' => now(),
        ]);

        $activeOrder = $this->order('FILTER-ACTIVE', $activeBranch, $activeProvince, $activeMerchant, $activeCourier);
        $disabledOrder = $this->order('FILTER-DISABLED', $disabledBranch, $disabledProvince, $disabledMerchant, $disabledCourier);
        $branchQuery = '?branch_id='.$disabledBranch->id;

        $allOrders = $this->actingAs($superAdmin)
            ->get('/__tests/dashboard-branch-filter/orders')
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertEqualsCanonicalizing([$activeOrder->id, $disabledOrder->id], $this->ids($allOrders['orders']['data']));
        $this->assertNull($allOrders['branchFilter']['selected_id']);

        $orders = $this->actingAs($superAdmin)
            ->get('/__tests/dashboard-branch-filter/orders'.$branchQuery)
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertSame([$disabledOrder->id], $this->ids($orders['orders']['data']));
        $this->assertTrue($orders['branchFilter']['enabled']);
        $this->assertSame($disabledBranch->id, $orders['branchFilter']['selected_id']);
        $this->assertContains($disabledBranch->id, $this->ids($orders['branchFilter']['branches']));

        $merchants = $this->actingAs($superAdmin)
            ->get('/__tests/dashboard-branch-filter/merchants'.$branchQuery)
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertSame([$disabledMerchant->id], $this->ids($merchants['rows']));
        $this->assertSame(1, $merchants['rows'][0]['orders']);

        $couriers = $this->actingAs($superAdmin)
            ->get('/__tests/dashboard-branch-filter/couriers'.$branchQuery)
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertSame([$disabledCourier->id], $this->ids($couriers['rows']));
        $this->assertSame(1, $couriers['rows'][0]['assigned']);

        $locations = $this->actingAs($superAdmin)
            ->get('/__tests/dashboard-branch-filter/courier-locations'.$branchQuery)
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertSame([$disabledCourier->id], $this->ids($locations['couriers']));

        $this->assertNotContains($activeOrder->id, $this->ids($orders['orders']['data']));
        $this->assertNotContains($activeMerchant->id, $this->ids($merchants['rows']));
        $this->assertNotContains($activeCourier->id, $this->ids($couriers['rows']));
    }

    public function test_branch_manager_cannot_override_the_primary_branch_with_a_query_parameter(): void
    {
        [$localBranch, $localProvince] = $this->branch('KRB-FILTER', 'كربلاء', true);
        [$foreignBranch, $foreignProvince] = $this->branch('NJF-FILTER', 'النجف', true);
        $manager = $this->user('branch_manager', 'local-filter-manager', $localBranch->id);
        app(BranchDashboardContext::class)->assignPrimaryMembership($manager, $localBranch);

        $localMerchant = $this->user('merchant', 'local-filter-merchant', $localBranch->id);
        $foreignMerchant = $this->user('merchant', 'foreign-filter-merchant', $foreignBranch->id);
        $localCourier = $this->user('courier', 'local-filter-courier', $localBranch->id, [
            'current_latitude' => 32.6167,
            'current_longitude' => 44.0244,
            'location_updated_at' => now(),
        ]);
        $foreignCourier = $this->user('courier', 'foreign-filter-courier', $foreignBranch->id, [
            'current_latitude' => 32.0000,
            'current_longitude' => 44.3333,
            'location_updated_at' => now(),
        ]);
        $localOrder = $this->order('FILTER-LOCAL', $localBranch, $localProvince, $localMerchant, $localCourier);
        $foreignOrder = $this->order('FILTER-FOREIGN', $foreignBranch, $foreignProvince, $foreignMerchant, $foreignCourier);
        $foreignQuery = '?branch_id='.$foreignBranch->id;

        $orders = $this->actingAs($manager)
            ->get('/__tests/dashboard-branch-filter/orders'.$foreignQuery)
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertSame([$localOrder->id], $this->ids($orders['orders']['data']));
        $this->assertFalse($orders['branchFilter']['enabled']);
        $this->assertSame($localBranch->id, $orders['branchFilter']['selected_id']);
        $this->assertSame([], $orders['branchFilter']['branches']);

        $merchants = $this->actingAs($manager)
            ->get('/__tests/dashboard-branch-filter/merchants'.$foreignQuery)
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertSame([$localMerchant->id], $this->ids($merchants['rows']));

        $couriers = $this->actingAs($manager)
            ->get('/__tests/dashboard-branch-filter/couriers'.$foreignQuery)
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertSame([$localCourier->id], $this->ids($couriers['rows']));

        $locations = $this->actingAs($manager)
            ->get('/__tests/dashboard-branch-filter/courier-locations'.$foreignQuery)
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertSame([$localCourier->id], $this->ids($locations['couriers']));

        $this->assertNotContains($foreignOrder->id, $this->ids($orders['orders']['data']));
        $this->assertNotContains($foreignMerchant->id, $this->ids($merchants['rows']));
        $this->assertNotContains($foreignCourier->id, $this->ids($couriers['rows']));
    }

    public function test_super_admin_cannot_select_a_soft_deleted_branch(): void
    {
        [$deletedBranch] = $this->branch('DEL-FILTER', 'فرع محذوف', true);
        $deletedBranch->delete();
        $superAdmin = $this->superAdmin('super-deleted-branch-filter');
        $path = '/__tests/dashboard-branch-filter/orders';

        $this->actingAs($superAdmin)
            ->from($path)
            ->get($path.'?branch_id='.$deletedBranch->id)
            ->assertRedirect($path)
            ->assertSessionHasErrors('branch_id');
    }

    public function test_super_admin_can_filter_the_branch_directory_with_the_same_branch_selector(): void
    {
        [$firstBranch] = $this->branch('DIRECTORY-A', 'فرع الدليل الأول', true);
        [$selectedBranch] = $this->branch('DIRECTORY-B', 'فرع الدليل الثاني', false);
        $superAdmin = $this->superAdmin('super-branch-directory-filter');

        $all = $this->actingAs($superAdmin)
            ->get('/__tests/dashboard-branch-filter/branches')
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertEqualsCanonicalizing([$firstBranch->id, $selectedBranch->id], $this->ids($all['branches']));

        $filtered = $this->actingAs($superAdmin)
            ->get('/__tests/dashboard-branch-filter/branches?branch_id='.$selectedBranch->id)
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertSame([$selectedBranch->id], $this->ids($filtered['branches']));
        $this->assertSame($selectedBranch->id, $filtered['branchFilter']['selected_id']);
    }

    public function test_super_admin_notification_audit_does_not_receive_recipients_from_other_branches(): void
    {
        [$selectedBranch] = $this->branch('NOTIFY-AUDIT', 'فرع الإشعارات', true);
        [$foreignBranch] = $this->branch('NOTIFY-FOREIGN', 'فرع آخر', true);
        $superAdmin = $this->superAdmin('super-notification-audit');
        $selectedMerchant = $this->user('merchant', 'notify-selected-merchant', $selectedBranch->id);
        $foreignMerchant = $this->user('merchant', 'notify-foreign-merchant', $foreignBranch->id);

        $props = $this->actingAs($superAdmin)
            ->get('/__tests/dashboard-branch-filter/notifications?branch_id='.$selectedBranch->id)
            ->assertOk()
            ->inertiaPage()['props'];

        $this->assertSame([$selectedMerchant->id], $this->ids($props['recipients']));
        $this->assertNotContains($foreignMerchant->id, $this->ids($props['recipients']));
    }

    public function test_super_admin_audits_only_the_selected_branchs_staff_and_profiles_without_global_writes(): void
    {
        [$auditBranch] = $this->branch('AUDIT-DISABLED', 'فرع مراجعة', false);
        [$foreignBranch] = $this->branch('AUDIT-FOREIGN', 'فرع آخر', true);
        $superAdmin = $this->superAdmin('super-staff-audit');

        $auditProfile = DashboardPermissionProfile::create([
            'branch_id' => $auditBranch->id,
            'name' => 'صلاحيات فرع المراجعة',
            'permissions' => ['orders' => ['view']],
        ]);
        $foreignProfile = DashboardPermissionProfile::create([
            'branch_id' => $foreignBranch->id,
            'name' => 'صلاحيات الفرع الآخر',
            'permissions' => ['orders' => ['view']],
        ]);
        $globalProfile = DashboardPermissionProfile::create([
            'name' => 'صلاحيات المنصة العامة',
            'permissions' => ['orders' => ['view']],
        ]);

        $auditPrincipal = $this->user('branch_manager', 'audit-principal', $auditBranch->id);
        $auditEmployee = $this->user('branch_manager', 'audit-employee', $auditBranch->id, [
            'permission_profile_id' => $auditProfile->id,
        ]);
        $foreignEmployee = $this->user('branch_manager', 'foreign-employee', $foreignBranch->id, [
            'permission_profile_id' => $foreignProfile->id,
        ]);
        $globalEmployee = $this->user('admin', 'global-employee', null, [
            'permission_profile_id' => $globalProfile->id,
        ]);
        $query = '?branch_id='.$auditBranch->id;

        $employees = $this->actingAs($superAdmin)
            ->get('/__tests/dashboard-branch-filter/employees'.$query)
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertEqualsCanonicalizing([$auditPrincipal->id, $auditEmployee->id], $this->ids($employees['employees']));
        $this->assertSame([$auditProfile->id], $this->ids($employees['profiles']));
        $this->assertSame(1, $employees['profiles'][0]['employees_count']);
        $this->assertTrue($employees['branchAudit']);
        $this->assertFalse($employees['canManageEmployees']);
        $this->assertSame($auditBranch->id, $employees['branchFilter']['selected_id']);
        $this->assertContains($auditBranch->id, $this->ids($employees['branchFilter']['branches']));
        $this->assertNotContains($foreignEmployee->id, $this->ids($employees['employees']));
        $this->assertNotContains($globalEmployee->id, $this->ids($employees['employees']));

        $permissions = $this->actingAs($superAdmin)
            ->get('/__tests/dashboard-branch-filter/permissions'.$query)
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertSame([$auditProfile->id], $this->ids($permissions['profiles']));
        $this->assertSame(1, $permissions['profiles'][0]['users_count']);
        $this->assertSame([$auditEmployee->id], $this->ids($permissions['profiles'][0]['users']));
        $this->assertEqualsCanonicalizing([$auditPrincipal->id, $auditEmployee->id], $this->ids($permissions['users']));
        $this->assertTrue($permissions['branchAudit']);
        $this->assertFalse($permissions['canManageProfiles']);
        $this->assertNotContains($foreignProfile->id, $this->ids($permissions['profiles']));
        $this->assertNotContains($globalProfile->id, $this->ids($permissions['profiles']));

        // A stale form that retains the selected branch id must never create
        // a global account or profile behind this read-only audit surface.
        $this->actingAs($superAdmin)
            ->post('/__tests/dashboard-branch-filter/employees'.$query, [
                'name' => 'محاولة موظف عالمي',
                'email' => 'blocked-global-employee@example.test',
                'password' => 'StrongPassword123!',
                'password_confirmation' => 'StrongPassword123!',
                'permission_profile_id' => $globalProfile->id,
            ])
            ->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'blocked-global-employee@example.test']);

        $this->actingAs($superAdmin)
            ->post('/__tests/dashboard-branch-filter/permissions'.$query, [
                'name' => 'محاولة صلاحية عامة',
                'permissions' => ['orders' => ['view']],
            ])
            ->assertForbidden();
        $this->assertDatabaseMissing('dashboard_permission_profiles', ['name' => 'محاولة صلاحية عامة']);

        $this->actingAs($superAdmin)
            ->put('/__tests/dashboard-branch-filter/employees/'.$globalEmployee->id.$query, [
                'name' => 'تعديل عالمي محظور',
                'email' => $globalEmployee->email,
                'permission_profile_id' => $globalProfile->id,
            ])
            ->assertForbidden();
        $this->assertSame($globalEmployee->name, $globalEmployee->fresh()->name);

        $this->actingAs($superAdmin)
            ->put('/__tests/dashboard-branch-filter/permissions/'.$globalProfile->id.$query, [
                'name' => 'تعديل صلاحية عامة محظور',
                'permissions' => ['orders' => ['view']],
            ])
            ->assertForbidden();
        $this->assertSame('صلاحيات المنصة العامة', $globalProfile->fresh()->name);
    }

    public function test_branch_manager_ignores_a_foreign_filter_and_keeps_its_local_write_scope(): void
    {
        [$localBranch] = $this->branch('STAFF-LOCAL', 'الفرع المحلي', true);
        [$foreignBranch] = $this->branch('STAFF-FOREIGN', 'الفرع الأجنبي', true);
        $manager = $this->user('branch_manager', 'local-staff-manager', $localBranch->id);
        app(BranchDashboardContext::class)->assignPrimaryMembership($manager, $localBranch);

        $localProfile = DashboardPermissionProfile::create([
            'branch_id' => $localBranch->id,
            'name' => 'صلاحيات الموظف المحلي',
            'permissions' => ['orders' => ['view']],
        ]);
        $foreignProfile = DashboardPermissionProfile::create([
            'branch_id' => $foreignBranch->id,
            'name' => 'صلاحيات الموظف الأجنبي',
            'permissions' => ['orders' => ['view']],
        ]);
        $localEmployee = $this->user('branch_manager', 'local-staff-employee', $localBranch->id, [
            'permission_profile_id' => $localProfile->id,
        ]);
        $foreignEmployee = $this->user('branch_manager', 'foreign-staff-employee', $foreignBranch->id, [
            'permission_profile_id' => $foreignProfile->id,
        ]);
        $foreignQuery = '?branch_id='.$foreignBranch->id;

        $employees = $this->actingAs($manager)
            ->get('/__tests/dashboard-branch-filter/employees'.$foreignQuery)
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertEqualsCanonicalizing([$manager->id, $localEmployee->id], $this->ids($employees['employees']));
        $this->assertSame([$localProfile->id], $this->ids($employees['profiles']));
        $this->assertFalse($employees['branchFilter']['enabled']);
        $this->assertSame($localBranch->id, $employees['branchFilter']['selected_id']);
        $this->assertFalse($employees['branchAudit']);
        $this->assertTrue($employees['canManageEmployees']);
        $this->assertNotContains($foreignEmployee->id, $this->ids($employees['employees']));

        $permissions = $this->actingAs($manager)
            ->get('/__tests/dashboard-branch-filter/permissions'.$foreignQuery)
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertSame([$localProfile->id], $this->ids($permissions['profiles']));
        $this->assertEqualsCanonicalizing([$manager->id, $localEmployee->id], $this->ids($permissions['users']));
        $this->assertFalse($permissions['branchFilter']['enabled']);
        $this->assertSame($localBranch->id, $permissions['branchFilter']['selected_id']);
        $this->assertFalse($permissions['branchAudit']);
        $this->assertTrue($permissions['canManageProfiles']);

        $this->actingAs($manager)
            ->post('/__tests/dashboard-branch-filter/employees'.$foreignQuery, [
                'name' => 'موظف محلي جديد',
                'email' => 'new-local-staff@example.test',
                'password' => 'StrongPassword123!',
                'password_confirmation' => 'StrongPassword123!',
                'permission_profile_id' => $localProfile->id,
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('users', [
            'email' => 'new-local-staff@example.test',
            'role' => 'branch_manager',
            'branch_id' => $localBranch->id,
            'permission_profile_id' => $localProfile->id,
        ]);
    }

    public function test_super_admin_financial_pages_apply_the_selected_branch_on_server_data_and_finance_json(): void
    {
        [$firstBranch, $firstProvince] = $this->branch('FIN-A', 'الأولى', true);
        [$selectedBranch, $selectedProvince] = $this->branch('FIN-B', 'الثانية', false);
        $superAdmin = $this->superAdmin('super-financial-filter');
        $firstMerchant = $this->user('merchant', 'finance-first-merchant', $firstBranch->id);
        $selectedMerchant = $this->user('merchant', 'finance-selected-merchant', $selectedBranch->id);
        $firstCourier = $this->user('courier', 'finance-first-courier', $firstBranch->id);
        $selectedCourier = $this->user('courier', 'finance-selected-courier', $selectedBranch->id);
        $firstCourier->wallet()->create(['balance' => 10_000, 'budget' => 0, 'budget_balance' => 0]);
        $selectedCourier->wallet()->create(['balance' => 20_000, 'budget' => 0, 'budget_balance' => 0]);
        $this->order('FIN-ORDER-A', $firstBranch, $firstProvince, $firstMerchant, $firstCourier);
        $this->order('FIN-ORDER-B', $selectedBranch, $selectedProvince, $selectedMerchant, $selectedCourier);

        $firstRequest = $this->financeRequest($firstCourier, $firstBranch, 'FIN-REQ-A');
        $selectedRequest = $this->financeRequest($selectedCourier, $selectedBranch, 'FIN-REQ-B');
        $firstTransaction = $this->transaction($firstCourier, $firstRequest, 'FIN-TX-A');
        $selectedTransaction = $this->transaction($selectedCourier, $selectedRequest, 'FIN-TX-B');

        $firstCashbox = $this->cashbox($firstBranch, 'صندوق الأولى');
        $selectedCashbox = $this->cashbox($selectedBranch, 'صندوق الثانية');
        $this->voucher($firstCashbox, $firstBranch, $superAdmin, 'FIN-VOUCHER-A');
        $selectedVoucher = $this->voucher($selectedCashbox, $selectedBranch, $superAdmin, 'FIN-VOUCHER-B');

        $firstRule = $this->pricingRule('تسعير الأولى', $firstProvince->id, $firstMerchant->id);
        $selectedRule = $this->pricingRule('تسعير الثانية', $selectedProvince->id, $selectedMerchant->id);
        $query = '?branch_id='.$selectedBranch->id;

        $finance = $this->actingAs($superAdmin)
            ->get('/__tests/dashboard-branch-filter/finance'.$query)
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertSame([$selectedRequest->id], $this->ids($finance['requests']));
        $this->assertSame([$selectedTransaction->id], $this->ids($finance['transactions']));
        $this->assertEqualsCanonicalizing([$selectedCourier->id, $selectedMerchant->id], $this->ids($finance['accounts']));
        $this->assertSame($selectedBranch->id, $finance['branchFilter']['selected_id']);

        $detail = $this->actingAs($superAdmin)
            ->getJson('/__tests/dashboard-branch-filter/finance'.$query.'&detail=courier_balances')
            ->assertOk()
            ->json();
        $this->assertSame([$selectedCourier->id], $this->ids($detail['rows']));

        $cashboxes = $this->actingAs($superAdmin)
            ->get('/__tests/dashboard-branch-filter/cashboxes'.$query)
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertSame([$selectedCashbox->id], $this->ids($cashboxes['cashboxes']));
        $this->assertSame([$selectedVoucher->id], $this->ids($cashboxes['vouchers']));

        $pricing = $this->actingAs($superAdmin)
            ->get('/__tests/dashboard-branch-filter/pricing'.$query)
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertSame([$selectedRule->id], $this->ids($pricing['rules']));
        $this->assertSame([$selectedMerchant->id], $this->ids($pricing['merchants']));

        $reports = $this->actingAs($superAdmin)
            ->get('/__tests/dashboard-branch-filter/reports'.$query)
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertSame(1, $reports['kpis']['orders']);
        $this->assertSame([$selectedBranch->id], $this->ids($reports['branches']));
        $this->assertSame([$selectedCourier->id], $this->ids($reports['couriers']));
        $this->assertSame([$selectedMerchant->id], $this->ids($reports['merchants']));
        $this->assertNotContains($firstRule->id, $this->ids($pricing['rules']));
        $this->assertNotContains($firstTransaction->id, $this->ids($finance['transactions']));
    }

    public function test_unassigned_branch_manager_cannot_fall_back_to_an_unfiltered_orders_query(): void
    {
        $unassignedManager = $this->user('branch_manager', 'unassigned-filter-manager');

        $this->actingAs($unassignedManager)
            ->get('/__tests/dashboard-branch-filter/orders')
            ->assertForbidden();
    }

    /** @return array{0:Branch,1:Province} */
    private function branch(string $code, string $provinceName, bool $isActive): array
    {
        $province = Province::create([
            'name_ar' => $provinceName,
            'name_en' => $provinceName,
            'name_ku' => $provinceName,
            'sort_order' => Province::query()->count() + 1,
            'is_active' => true,
        ]);
        $branch = Branch::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => Tenant::platform()->id,
            'is_platform_managed' => true,
            'code' => $code,
            'name_ar' => 'فرع '.$provinceName,
            'province_id' => $province->id,
            'is_active' => $isActive,
        ]);

        return [$branch, $province];
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

    private function superAdmin(string $username): User
    {
        $user = $this->user('admin', $username);
        $user->forceFill(['is_super_admin' => true])->save();

        return $user->fresh();
    }

    private function order(string $trackNo, Branch $branch, Province $province, User $merchant, User $courier): Order
    {
        return Order::withoutGlobalScopes()->create([
            'tenant_id' => Tenant::platform()->id,
            'track_no' => $trackNo,
            'source' => 'merchant',
            'customer_name_ar' => 'عميل اختبار',
            'phone' => '07800000000',
            'address_ar' => 'عنوان اختبار',
            'delivery_vehicle' => 'normal',
            'price' => 15_000,
            'fee' => 2_000,
            'status' => 'pending',
            'workflow_stage' => 'awaiting_pickup',
            'branch_id' => $branch->id,
            'origin_branch_id' => $branch->id,
            'merchant_id' => $merchant->id,
            'courier_id' => $courier->id,
            'province_id' => $province->id,
            'date' => today(),
        ]);
    }

    private function financeRequest(User $courier, Branch $branch, string $reference): FinanceRequest
    {
        return FinanceRequest::withoutGlobalScopes()->create([
            'tenant_id' => Tenant::platform()->id,
            'user_id' => $courier->id,
            'branch_id' => $branch->id,
            'type' => FinanceRequest::QI_TOPUP,
            'amount' => 5_000,
            'approved_amount' => 5_000,
            'status' => FinanceRequest::APPROVED,
            'reference' => $reference,
            'processed_at' => now(),
        ]);
    }

    private function transaction(User $courier, FinanceRequest $request, string $reference): Transaction
    {
        return Transaction::withoutGlobalScopes()->create([
            'tenant_id' => Tenant::platform()->id,
            'finance_request_id' => $request->id,
            'user_id' => $courier->id,
            'type' => FinanceRequest::QI_TOPUP,
            'amount' => 5_000,
            'direction' => 1,
            'ref' => $reference,
            'date' => today(),
        ]);
    }

    private function cashbox(Branch $branch, string $name): Cashbox
    {
        return Cashbox::withoutGlobalScopes()->create([
            'tenant_id' => Tenant::platform()->id,
            'branch_id' => $branch->id,
            'kind' => 'branch',
            'name_ar' => $name,
            'balance' => 5_000,
            'is_active' => true,
        ]);
    }

    private function voucher(Cashbox $cashbox, Branch $branch, User $actor, string $reference): CashboxVoucher
    {
        return CashboxVoucher::withoutGlobalScopes()->create([
            'tenant_id' => Tenant::platform()->id,
            'cashbox_id' => $cashbox->id,
            'branch_id' => $branch->id,
            'actor_id' => $actor->id,
            'type' => 'courier_handover',
            'direction' => 1,
            'amount' => 5_000,
            'reference' => $reference,
            'occurred_at' => now(),
        ]);
    }

    private function pricingRule(string $name, int $originProvinceId, ?int $merchantId): PricingRule
    {
        return PricingRule::withoutGlobalScopes()->create([
            'tenant_id' => Tenant::platform()->id,
            'name_ar' => $name,
            'merchant_id' => $merchantId,
            'origin_province_id' => $originProvinceId,
            'min_weight_grams' => 0,
            'base_fee' => 5_000,
            'return_fee' => 0,
            'priority' => 100,
            'is_active' => true,
        ]);
    }

    /** @param array<int, array{id:int}> $rows
     * @return array<int, int>
     */
    private function ids(array $rows): array
    {
        return collect($rows)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
