<?php

namespace Tests\Feature;

use App\Models\DashboardPermissionProfile;
use App\Models\Document;
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

    public function test_complete_named_profile_receives_the_complete_dashboard_like_a_super_administrator(): void
    {
        $permissions = collect(DashboardPermissionProfile::MODULES)
            ->mapWithKeys(fn (array $module, string $key): array => [$key => array_keys($module['actions'])])
            ->all();
        $profile = DashboardPermissionProfile::create([
            'name' => 'وصول كامل',
            'permissions' => $permissions,
        ]);
        $operator = $this->operator($profile);

        $this->assertTrue($operator->isSuperAdmin());
        $this->actingAs($operator)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard'));
        $this->actingAs($operator)
            ->get('/dashboard/loyalty')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Loyalty'));
    }

    public function test_orders_view_profile_can_open_orders_but_cannot_change_an_order_until_the_legacy_grant_is_expanded(): void
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
        $this->assertFalse($operator->canUseAdminPermission('orders', 'edit'));
        $this->assertFalse($operator->canUseAdminPermission('orders', 'delete'));
    }

    public function test_legacy_permissions_only_expand_to_operations_that_existed_before_the_matrix(): void
    {
        $legacy = new DashboardPermissionProfile([
            'permissions' => [
                'orders' => ['update'],
                'branches' => ['update'],
                'merchants' => ['update'],
                'couriers' => ['update'],
            ],
        ]);

        $this->assertTrue($legacy->allows('orders', 'change_status'));
        $this->assertTrue($legacy->allows('orders', 'assign_courier'));
        $this->assertFalse($legacy->allows('orders', 'edit'));
        $this->assertFalse($legacy->allows('orders', 'delete'));

        $this->assertTrue($legacy->allows('branches', 'manage_access'));
        $this->assertFalse($legacy->allows('branches', 'delete'));

        $this->assertTrue($legacy->allows('merchants', 'documents_review'));
        $this->assertFalse($legacy->allows('merchants', 'documents_view'));

        $this->assertTrue($legacy->allows('couriers', 'update_deduction'));
        $this->assertTrue($legacy->allows('couriers', 'documents_review'));
        $this->assertFalse($legacy->allows('couriers', 'documents_view'));
        $this->assertFalse($legacy->allows('couriers', 'verify'));
    }

    public function test_granular_actions_are_isolated_and_required_read_access_is_added_automatically(): void
    {
        $permissions = DashboardPermissionProfile::normalizePermissions([
            'orders' => ['edit' => true],
            'merchants' => ['documents_review' => true],
            'finance' => ['record_settlement' => true],
        ]);

        $this->assertSame([
            'orders' => ['view', 'edit'],
            'merchants' => ['view', 'documents_review'],
            'finance' => ['view', 'record_settlement'],
        ], $permissions);

        $profile = DashboardPermissionProfile::create([
            'name' => 'محرر طلبات فقط',
            'permissions' => $permissions,
        ]);
        $operator = $this->operator($profile);
        $order = Order::query()->where('status', 'pending')->firstOrFail();

        $this->assertTrue($operator->canUseAdminPermission('orders', 'view'));
        $this->assertTrue($operator->canUseAdminPermission('orders', 'edit'));
        $this->assertFalse($operator->canUseAdminPermission('orders', 'change_status'));
        $this->assertFalse($operator->canUseAdminPermission('orders', 'assign_courier'));
        $this->assertFalse($operator->canUseAdminPermission('orders', 'delete'));

        $this->actingAs($operator)
            ->post("/dashboard/orders/{$order->id}/status", ['status' => 'approved'])
            ->assertForbidden();
    }

    public function test_global_courier_deduction_default_requires_its_own_settings_capability(): void
    {
        Setting::set('admin_deduction_fee', 1_700);

        $legacyProfile = DashboardPermissionProfile::create([
            'name' => 'إعدادات قديمة',
            'permissions' => ['settings' => ['view', 'update']],
        ]);
        $operator = $this->operator($legacyProfile);

        // Older compiled clients may still post every former settings field.
        // The compatibility endpoint intentionally ignores the newer global
        // courier deduction even when a caller injects it into that payload.
        $this->actingAs($operator)
            ->post(route('admin.settings.update'), [
                'brand_name' => 'المنجز',
                'brand_tagline' => 'اختبار',
                'support_phone' => '07700000000',
                'support_email' => 'support@example.test',
                'currency' => 'IQD',
                'delivery_fee' => 4_500,
                'admin_deduction_fee' => 9_999,
                'order_expiry_minutes' => 30,
                'pickup_eta_minutes' => 20,
            ])
            ->assertRedirect();
        $this->assertSame(1_700, (int) Setting::get('admin_deduction_fee'));

        $this->actingAs($operator)
            ->post(route('admin.settings.courier-deduction-default.update'), [
                'admin_deduction_fee' => 2_300,
            ])
            ->assertForbidden();

        $legacyProfile->update([
            'permissions' => ['settings' => ['view', 'update_financial_defaults']],
        ]);
        $operator->refresh();

        $this->actingAs($operator)
            ->post(route('admin.settings.financial-defaults.update'), [
                'delivery_fee' => 5_500,
                'admin_deduction_fee' => 8_888,
            ])
            ->assertRedirect();
        $this->assertSame(5_500, (int) Setting::get('delivery_fee'));
        $this->assertSame(1_700, (int) Setting::get('admin_deduction_fee'));

        $legacyProfile->update([
            'permissions' => ['settings' => ['view', 'update_courier_deduction_default']],
        ]);
        $operator->refresh();

        $this->actingAs($operator)
            ->post(route('admin.settings.courier-deduction-default.update'), [
                'admin_deduction_fee' => 2_300,
            ])
            ->assertRedirect();
        $this->assertSame(2_300, (int) Setting::get('admin_deduction_fee'));
    }

    public function test_courier_deduction_requires_its_own_capability_instead_of_courier_edit(): void
    {
        $profile = DashboardPermissionProfile::create([
            'name' => 'محرر ملفات المندوبين',
            'permissions' => ['couriers' => ['edit']],
        ]);
        $operator = $this->operator($profile);
        $courier = User::query()->where('role', 'courier')->firstOrFail();
        $courier->update(['admin_deduction_per_order' => 1_250]);

        $this->actingAs($operator)
            ->patch(route('admin.users.courier-deduction.update', $courier), [
                'admin_deduction_per_order' => 3_500,
            ])
            ->assertForbidden();
        $this->assertSame(1_250, $courier->fresh()->admin_deduction_per_order);

        $profile->update([
            'permissions' => ['couriers' => ['edit', 'update_deduction']],
        ]);
        $operator->refresh();

        $this->actingAs($operator)
            ->patch(route('admin.users.courier-deduction.update', $courier), [
                'admin_deduction_per_order' => -1,
            ])
            ->assertSessionHasErrors('admin_deduction_per_order');
        $this->assertSame(1_250, $courier->fresh()->admin_deduction_per_order);

        $this->actingAs($operator)
            ->patch(route('admin.users.courier-deduction.update', $courier), [
                'admin_deduction_per_order' => 3_500,
            ])
            ->assertRedirect();

        $this->assertSame(3_500, $courier->fresh()->admin_deduction_per_order);
        $this->assertDatabaseHas('activity_logs', [
            'tenant_id' => $courier->tenant_id,
            'user_id' => $operator->id,
            'action' => 'courier.admin_deduction_updated',
            'subject_type' => User::class,
            'subject_id' => $courier->id,
        ]);
    }

    public function test_courier_deduction_migration_only_grants_unambiguous_legacy_update_profiles(): void
    {
        $explicit = DashboardPermissionProfile::create([
            'name' => 'صلاحيات مندوبي مختارة بدقة',
            'permissions' => [
                'couriers' => ['edit', 'change_status', 'documents_review'],
            ],
        ]);
        $partial = DashboardPermissionProfile::create([
            'name' => 'صلاحيات مندوبي جزئية',
            'permissions' => [
                'couriers' => ['edit', 'change_status'],
            ],
        ]);
        $legacy = DashboardPermissionProfile::create([
            'name' => 'تحديث مندوبي قديم',
            'permissions' => ['couriers' => ['update']],
        ]);

        $migration = require database_path('migrations/2026_09_04_110000_grant_courier_deduction_permission_to_complete_legacy_profiles.php');
        $migration->up();

        $this->assertNotContains('update_deduction', $explicit->fresh()->permissions['couriers']);
        $this->assertNotContains('update_deduction', $partial->fresh()->permissions['couriers']);
        $this->assertContains('update_deduction', $legacy->fresh()->permissions['couriers']);
    }

    public function test_legacy_courier_update_expands_to_the_dedicated_deduction_capability(): void
    {
        $permissions = DashboardPermissionProfile::normalizePermissions([
            'couriers' => ['update'],
        ]);

        $this->assertSame([
            'couriers' => [
                'view',
                'edit',
                'update_deduction',
                'change_status',
                'documents_review',
            ],
        ], $permissions);
    }

    public function test_view_only_roster_profiles_do_not_receive_sensitive_financial_values(): void
    {
        $profile = DashboardPermissionProfile::create([
            'name' => 'عرض الحسابات فقط',
            'permissions' => [
                'merchants' => ['view'],
                'couriers' => ['view'],
            ],
        ]);
        $operator = $this->operator($profile);

        $merchantResponse = $this->actingAs($operator)
            ->get('/dashboard/merchants')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Roster')
                ->where('canViewFinanceBalances', false)
                ->where('canViewDocumentRecords', false)
                ->where('canViewLoyalty', false)
                ->where('canUpdateCourierDeduction', false));
        $merchantRow = $merchantResponse->inertiaProps('rows.0');

        $courierResponse = $this->actingAs($operator)
            ->get('/dashboard/couriers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Roster')
                ->where('canViewFinanceBalances', false)
                ->where('canViewDocumentRecords', false)
                ->where('canViewLoyalty', false)
                ->where('canUpdateCourierDeduction', false));
        $courierRow = $courierResponse->inertiaProps('rows.0');

        foreach ([$merchantRow, $courierRow] as $row) {
            $this->assertArrayNotHasKey('wallet_balance', $row);
            $this->assertArrayNotHasKey('cash_budget', $row);
            $this->assertArrayNotHasKey('cash_budget_balance', $row);
            $this->assertArrayNotHasKey('collected', $row);
        }
        $this->assertArrayNotHasKey('admin_deduction_per_order', $courierRow);
        $this->assertArrayNotHasKey('admin_deduction_per_order', $courierRow['user']);
        $this->assertArrayNotHasKey('points_balance', $courierRow);
        $this->assertArrayNotHasKey('identity_number', $merchantRow['user']);
        $this->assertArrayNotHasKey('identity_number', $courierRow['user']);
        foreach ([$merchantRow, $courierRow] as $row) {
            $this->assertArrayNotHasKey('document_review', $row);
            $this->assertArrayNotHasKey('pendingDocs', $row);
            $this->assertArrayNotHasKey('documents', $row);
        }
    }

    public function test_roster_only_includes_points_and_identity_for_their_precise_read_actions(): void
    {
        $profile = DashboardPermissionProfile::create([
            'name' => 'مدقق وثائق ونقاط المندوبين',
            'permissions' => [
                'couriers' => ['view', 'documents_view'],
                'loyalty' => ['view'],
            ],
        ]);
        $operator = $this->operator($profile);

        $response = $this->actingAs($operator)
            ->get('/dashboard/couriers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Roster')
                ->where('canViewDocuments', true)
                ->where('canViewDocumentRecords', true)
                ->where('canViewLoyalty', true));
        $row = $response->inertiaProps('rows.0');

        $this->assertArrayHasKey('points_balance', $row);
        $this->assertArrayHasKey('identity_number', $row['user']);
        $this->assertArrayHasKey('documents', $row);
    }

    public function test_document_reviewer_gets_review_metadata_without_document_images_or_identity_number(): void
    {
        $profile = DashboardPermissionProfile::create([
            'name' => 'مراجع مستندات المندوبين',
            'permissions' => [
                'couriers' => ['view', 'documents_review'],
            ],
        ]);
        $operator = $this->operator($profile);

        $response = $this->actingAs($operator)
            ->get('/dashboard/couriers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Roster')
                ->where('canViewDocuments', false)
                ->where('canReviewDocuments', true)
                ->where('canViewDocumentRecords', true));
        $row = $response->inertiaProps('rows.0');

        $this->assertArrayHasKey('document_review', $row);
        $this->assertNotEmpty($row['documents']);
        $this->assertArrayNotHasKey('identity_number', $row['user']);
        foreach ($row['documents'] as $document) {
            $this->assertNull($document['url']);
        }
    }

    public function test_document_reviewer_cannot_revoke_a_verified_courier_without_the_verify_action(): void
    {
        $profile = DashboardPermissionProfile::create([
            'name' => 'مراجع مستندات فقط',
            'permissions' => ['couriers' => ['view', 'documents_review']],
        ]);
        $operator = $this->operator($profile);
        $courier = User::query()->where('role', 'courier')->firstOrFail();
        $document = Document::query()->where('user_id', $courier->id)->firstOrFail();
        $document->update(['status' => 'pending']);
        $courier->update([
            'courier_verified' => true,
            'courier_verified_at' => now(),
            'courier_verified_by' => $this->superAdmin()->id,
            'is_online' => true,
        ]);

        $this->actingAs($operator)
            ->post(route('admin.users.documents.review', [$courier, $document]), ['status' => 'rejected'])
            ->assertForbidden();
        $this->assertSame('pending', $document->fresh()->status);
        $this->assertTrue($courier->fresh()->isCourierVerified());
        $this->assertTrue((bool) $courier->fresh()->is_online);

        $profile->update([
            'permissions' => ['couriers' => ['view', 'documents_review', 'verify']],
        ]);
        $operator->refresh();

        $this->actingAs($operator)
            ->post(route('admin.users.documents.review', [$courier, $document]), ['status' => 'rejected'])
            ->assertRedirect();
        $this->assertSame('rejected', $document->fresh()->status);
        $this->assertFalse($courier->fresh()->isCourierVerified());
        $this->assertFalse((bool) $courier->fresh()->is_online);
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
        $this->assertSame([
            'orders' => [
                'view',
                'change_status',
                'assign_courier',
                'reoffer_overdue_pickup',
                'assign_branches',
                'restore',
                'delete',
            ],
        ], $profile->permissions);

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
                ->where('canViewProvinces', false)
                ->missingAll(['slides', 'settings.points_per_delivery'])
                ->etc());

        $props = $response->inertiaPage()['props'];
        $this->assertArrayNotHasKey('slides', $props);
        $this->assertArrayNotHasKey('points_per_delivery', $props['settings']);
    }

    public function test_content_only_profile_manages_the_slider_from_settings_without_receiving_other_settings(): void
    {
        Setting::set('delivery_fee', 12_345);
        $slide = MobileSlide::create([
            'audience' => 'all',
            'title_ar' => 'سلايدر إعدادات المحتوى',
            'is_active' => true,
        ]);
        $profile = DashboardPermissionProfile::create([
            'name' => 'مشاهدة السلايدر فقط',
            'permissions' => ['content' => ['view']],
        ]);
        $operator = $this->operator($profile);

        $this->assertSame('/dashboard/settings?tab=slider', $operator->firstAdminDashboardPath());

        $response = $this->actingAs($operator)
            ->get('/dashboard/settings?tab=slider')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings')
                ->where('canViewSettings', false)
                ->where('canViewContent', true)
                ->where('canCreateContent', false)
                ->has('slides', 1)
                ->where('slides.0.id', $slide->id)
                ->where('settings.delivery_fee', 0)
            );

        $this->actingAs($operator)
            ->get('/dashboard/content')
            ->assertNotFound();
        $this->actingAs($operator)
            ->post(route('admin.settings.slides.store'), [
                'audience' => 'all',
                'title_ar' => 'محاولة بدون صلاحية إنشاء',
                'is_active' => true,
                'sort_order' => 1,
            ])
            ->assertForbidden();
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
