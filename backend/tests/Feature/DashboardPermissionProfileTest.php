<?php

namespace Tests\Feature;

use App\Models\DashboardPermissionProfile;
use App\Models\MobileSlide;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LoyaltyPointService;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardPermissionProfileTest extends TestCase
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

    public function test_existing_bootstrap_administrator_keeps_explicit_super_access_to_dashboard_and_admin_api(): void
    {
        $superAdmin = $this->superAdmin();

        $this->assertTrue($superAdmin->isSuperAdmin());

        $this->actingAs($superAdmin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard'));

        Sanctum::actingAs($superAdmin);
        $this->getJson('/api/v1/dashboard')->assertOk();
        $this->getJson('/api/v1/admin/users')->assertOk();
    }

    public function test_profileless_administrator_is_denied_dashboard_data_and_permissions_administration(): void
    {
        $operator = $this->operator();

        $this->assertFalse($operator->isSuperAdmin());
        $this->assertNull($operator->permission_profile_id);

        $this->actingAs($operator)
            ->get('/dashboard')
            ->assertForbidden();
        $this->actingAs($operator)
            ->get('/dashboard/orders')
            ->assertForbidden();
        $this->actingAs($operator)
            ->get('/dashboard/permissions')
            ->assertForbidden();
    }

    public function test_orders_view_profile_can_open_orders_but_cannot_change_an_order_until_update_is_assigned(): void
    {
        $profile = DashboardPermissionProfile::create([
            'name' => 'مشاهدة الطلبات',
            'permissions' => ['orders' => ['view']],
        ]);
        $operator = $this->operator($profile);
        $order = Order::query()->where('status', 'pending')->firstOrFail();

        $this->actingAs($operator)
            ->get('/dashboard/orders')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Orders'));
        $this->actingAs($operator)
            ->post("/dashboard/orders/{$order->id}/status", ['status' => 'approved'])
            ->assertForbidden();

        $profile->update(['permissions' => ['orders' => ['view', 'update']]]);
        $operator->refresh();

        $this->actingAs($operator)
            ->post("/dashboard/orders/{$order->id}/status", ['status' => 'approved'])
            ->assertRedirect();
        $this->assertSame('approved', $order->fresh()->status);
    }

    public function test_target_aware_user_route_does_not_allow_merchant_operator_to_change_courier(): void
    {
        $profile = DashboardPermissionProfile::create([
            'name' => 'إدارة التجار',
            'permissions' => ['merchants' => ['view', 'update']],
        ]);
        $operator = $this->operator($profile);
        $merchant = User::query()->where('role', 'merchant')->firstOrFail();
        $courier = User::query()->where('role', 'courier')->firstOrFail();

        $this->actingAs($operator)
            ->post("/dashboard/users/{$merchant->id}/status", ['status' => 'suspended'])
            ->assertRedirect();
        $this->assertSame('suspended', $merchant->fresh()->status);

        $this->actingAs($operator)
            ->post("/dashboard/users/{$courier->id}/status", ['status' => 'suspended'])
            ->assertForbidden();
        $this->assertSame('active', $courier->fresh()->status);
    }

    public function test_restricted_profile_cannot_bypass_browser_matrix_through_mobile_api(): void
    {
        $profile = DashboardPermissionProfile::create([
            'name' => 'مشغل الطلبات',
            'permissions' => ['orders' => ['view', 'update']],
        ]);
        $operator = $this->operator($profile);

        Sanctum::actingAs($operator);

        $this->getJson('/api/v1/dashboard')->assertForbidden();
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/admin/couriers/locations')->assertForbidden();

        // This assertion targets the authorization boundary, not the app's
        // separately configured login throttling policy.
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->postJson('/api/v1/auth/login', [
            'username' => $operator->username,
            'password' => 'Password123!',
            'device_name' => 'restricted-operator-test',
        ])->assertForbidden();
    }

    public function test_courier_locations_view_is_effective_for_the_browser_profile_but_not_the_mobile_api(): void
    {
        $profile = DashboardPermissionProfile::create([
            'name' => 'موقع المندوبين',
            'permissions' => ['courier_locations' => ['view']],
        ]);
        $operator = $this->operator($profile);

        // This is a separately authorised Inertia screen, so it is a valid
        // landing destination for an operator whose sole capability is map
        // visibility.
        $this->assertSame('/dashboard/couriers/locations', $operator->firstAdminDashboardPath());

        $this->actingAs($operator)
            ->get('/dashboard/couriers/locations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/CourierLocations')
                ->has('couriers'));

        Sanctum::actingAs($operator);
        $this->getJson('/api/v1/admin/couriers/locations')->assertForbidden();
    }

    public function test_platform_create_operator_cannot_use_an_invitation_to_assign_a_stronger_profile(): void
    {
        $profile = DashboardPermissionProfile::create([
            'name' => 'إدارة المنصة المحدودة',
            'permissions' => ['platform' => ['view', 'create']],
        ]);
        $operator = $this->operator($profile);

        $this->actingAs($operator)
            ->post('/dashboard/platform/invitations', [
                'name' => 'حساب تصعيد',
                'email' => 'escalation@example.test',
                'expires_in_days' => 7,
                'permission_profile_id' => $profile->id,
            ])
            ->assertForbidden();
        $this->assertDatabaseMissing('dashboard_invitations', ['email' => 'escalation@example.test']);
    }

    public function test_only_super_administrator_can_create_profiles_and_assign_them_to_dashboard_staff(): void
    {
        $superAdmin = $this->superAdmin();
        $operator = $this->operator();

        $this->actingAs($operator)
            ->post('/dashboard/permissions', [
                'name' => 'محاولة تصعيد',
                'permissions' => ['orders' => ['view', 'update']],
            ])
            ->assertForbidden();

        $this->actingAs($superAdmin)
            ->post('/dashboard/permissions', [
                'name' => 'مشغل الطلبات',
                // Checkbox-map input is normalized server-side as well.
                'permissions' => [
                    'orders' => ['view' => true, 'update' => true, 'delete' => true],
                    'unknown_module' => ['view' => true],
                ],
            ])
            ->assertRedirect();

        $profile = DashboardPermissionProfile::query()->where('name', 'مشغل الطلبات')->firstOrFail();
        $this->assertSame(['orders' => ['view', 'update']], $profile->permissions);

        $this->actingAs($superAdmin)
            ->put("/dashboard/permissions/users/{$operator->id}", ['permission_profile_id' => $profile->id])
            ->assertRedirect();
        $this->assertSame($profile->id, $operator->fresh()->permission_profile_id);

        $this->actingAs($superAdmin)
            ->put("/dashboard/permissions/users/{$superAdmin->id}", ['permission_profile_id' => $profile->id])
            ->assertStatus(422);
        $this->assertTrue($superAdmin->fresh()->isSuperAdmin());
    }

    public function test_settings_view_only_profile_does_not_receive_content_or_loyalty_data(): void
    {
        Setting::set(LoyaltyPointService::POINTS_PER_DELIVERY_KEY, 77);
        MobileSlide::create([
            'audience' => 'all',
            'title_ar' => 'شريحة لا تخص مشاهد الإعدادات',
            'is_active' => true,
        ]);

        $profile = DashboardPermissionProfile::create([
            'name' => 'مشاهدة الإعدادات فقط',
            'permissions' => ['settings' => ['view']],
        ]);
        $operator = $this->operator($profile);

        $response = $this->actingAs($operator)
            ->get('/dashboard/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings')
                ->where('canUpdateSettings', false)
                ->where('canViewContent', false)
                ->where('canViewLoyalty', false)
                ->where('canUpdateLoyalty', false)
                ->missingAll(['slides', 'settings.points_per_delivery'])
                ->etc());

        $props = $response->inertiaPage()['props'];
        $this->assertArrayNotHasKey('slides', $props);
        $this->assertArrayNotHasKey('points_per_delivery', $props['settings']);
    }

    public function test_view_only_profile_does_not_receive_management_directories_or_staff_roster(): void
    {
        $profile = DashboardPermissionProfile::create([
            'name' => 'مدقق قراءة فقط',
            'permissions' => [
                'orders' => ['view'],
                'branches' => ['view'],
                'finance' => ['view'],
                'pricing' => ['view'],
                'platform' => ['view'],
                'notifications' => ['view'],
            ],
        ]);
        $operator = $this->operator($profile);

        $notifications = $this->actingAs($operator)->get('/dashboard/notifications')->assertOk();
        $notificationProps = $notifications->inertiaPage()['props'];
        $this->assertFalse($notificationProps['canCreateNotifications']);
        $this->assertArrayNotHasKey('recipients', $notificationProps);

        $finance = $this->actingAs($operator)->get('/dashboard/finance')->assertOk();
        $financeProps = $finance->inertiaPage()['props'];
        $this->assertFalse($financeProps['canUpdateFinance']);
        $this->assertArrayNotHasKey('accounts', $financeProps);
        $this->assertArrayNotHasKey('branches', $financeProps);

        $courierId = User::query()->where('role', 'courier')->value('id');
        $orders = $this->actingAs($operator)
            ->get('/dashboard/orders?courier_id='.$courierId)
            ->assertOk();
        $orderProps = $orders->inertiaPage()['props'];
        $this->assertFalse($orderProps['canUpdateOrders']);
        $this->assertNull($orderProps['courierId']);
        $this->assertArrayNotHasKey('couriers', $orderProps);
        $this->assertArrayNotHasKey('courierFilters', $orderProps);
        $this->assertArrayNotHasKey('branches', $orderProps);

        $pricing = $this->actingAs($operator)->get('/dashboard/pricing')->assertOk();
        $pricingProps = $pricing->inertiaPage()['props'];
        $this->assertFalse($pricingProps['canManagePricing']);
        $this->assertFalse($pricingProps['canCreatePricing']);
        $this->assertFalse($pricingProps['canUpdatePricing']);
        $this->assertArrayNotHasKey('merchants', $pricingProps);

        $branches = $this->actingAs($operator)->get('/dashboard/branches')->assertOk();
        $branchProps = $branches->inertiaPage()['props'];
        $this->assertFalse($branchProps['canManageBranches']);
        $this->assertFalse($branchProps['canCreateBranches']);
        $this->assertFalse($branchProps['canUpdateBranches']);
        $this->assertArrayNotHasKey('accessUsers', $branchProps);
        $this->assertArrayNotHasKey('provinces', $branchProps);
        $this->assertArrayNotHasKey('dashboardPermissions', $branchProps);
        foreach ($branchProps['branches'] as $branch) {
            $this->assertArrayNotHasKey('access_accounts', $branch);
        }

        $platform = $this->actingAs($operator)->get('/dashboard/platform')->assertOk();
        $platformProps = $platform->inertiaPage()['props'];
        $this->assertFalse($platformProps['canManageOperators']);
        $this->assertFalse($platformProps['canCreatePlatform']);
        $this->assertFalse($platformProps['canUpdatePlatform']);
        $this->assertArrayNotHasKey('operators', $platformProps);
        $this->assertArrayNotHasKey('invitations', $platformProps);
        $this->assertArrayNotHasKey('operators', $platformProps['summary']);
    }

    private function superAdmin(): User
    {
        return User::query()->where('username', 'admin')->firstOrFail();
    }

    private function operator(?DashboardPermissionProfile $profile = null): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create([
            'tenant_id' => Tenant::platform()->id,
            'name' => "مشغل اختبار {$sequence}",
            'username' => "permission-operator-{$sequence}",
            'email' => "permission-operator-{$sequence}@example.test",
            'phone' => '0798000'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'password' => 'Password123!',
            'role' => 'admin',
            'status' => 'active',
            'permission_profile_id' => $profile?->id,
            'is_super_admin' => false,
        ]);
    }
}
