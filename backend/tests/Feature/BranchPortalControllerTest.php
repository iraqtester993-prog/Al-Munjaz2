<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\BranchPortalController;
use App\Models\Branch;
use App\Models\BranchMembership;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchPortalControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::clear();

        // The production route is deliberately added by the dashboard work.
        // A test-only route lets this isolated domain test cover controller
        // authorisation without changing the application's route contract.
        Route::get('/__tests/branch-portal', [BranchPortalController::class, 'index']);
        Route::post('/__tests/branch-portal/orders/{order}/status', [BranchPortalController::class, 'statusOrder'])->middleware(SubstituteBindings::class);
        Route::put('/__tests/branch-portal/users/{user}', [BranchPortalController::class, 'updateUser'])->middleware(SubstituteBindings::class);
        Route::post('/__tests/branch-portal/users/{user}/status', [BranchPortalController::class, 'statusUser'])->middleware(SubstituteBindings::class);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    public function test_owner_only_receives_explicit_owner_memberships_and_their_orders(): void
    {
        [$platform, $otherTenant] = $this->tenants();
        $visible = $this->branch($platform, 'BGD-01', 'فرع بغداد');
        $hidden = $this->branch($platform, 'BSR-01', 'فرع البصرة');
        $owner = $this->user($platform, 'owner', 'portal-owner');

        $owner->managedBranches()->attach($visible->id, ['access_role' => BranchMembership::OWNER]);
        // An owner account must not gain access from a lower-level membership
        // accidentally attached to it.
        $owner->managedBranches()->attach($hidden->id, ['access_role' => BranchMembership::MANAGER]);

        $this->order($otherTenant, 'ORD-VISIBLE', $visible);
        $this->order($otherTenant, 'ORD-HIDDEN', $hidden);

        // Proves the portal intentionally escapes the currently selected
        // tenant scope and uses the membership boundary instead.
        TenantContext::set($otherTenant);

        $this->actingAs($owner)
            ->withHeader('X-Inertia', 'true')
            ->get('/__tests/branch-portal')
            ->assertOk()
            ->assertJsonPath('component', 'Admin/BranchPortal')
            ->assertJsonCount(1, 'props.branches')
            ->assertJsonPath('props.branches.0.id', $visible->id)
            ->assertJsonPath('props.branches.0.access_role', BranchMembership::OWNER)
            ->assertJsonCount(1, 'props.recentOrders')
            ->assertJsonPath('props.recentOrders.0.track_no', 'ORD-VISIBLE')
            ->assertJsonPath('props.summary.branches', 1)
            ->assertJsonMissingPath('props.orders')
            ->assertJsonMissingPath('props.merchants')
            ->assertJsonMissingPath('props.couriers');
    }

    public function test_branch_manager_cannot_use_the_legacy_owner_portal(): void
    {
        [$platform, $otherTenant] = $this->tenants();
        $memberBranch = $this->branch($platform, 'KRB-01', 'فرع كربلاء');
        $hiddenBranch = $this->branch($platform, 'MYS-01', 'فرع ميسان');
        $manager = $this->user($platform, 'branch_manager', 'portal-manager');

        $manager->managedBranches()->attach($memberBranch->id, ['access_role' => BranchMembership::MANAGER]);
        $manager->managedBranches()->attach($hiddenBranch->id, ['access_role' => BranchMembership::OWNER]);

        $this->order($otherTenant, 'ORD-MEMBER', $memberBranch);
        $this->order($otherTenant, 'ORD-HIDDEN-MANAGER', $hiddenBranch);

        TenantContext::set($otherTenant);

        $this->actingAs($manager)
            ->withHeader('X-Inertia', 'true')
            ->get('/__tests/branch-portal')
            ->assertForbidden();
    }

    public function test_disabled_branch_membership_does_not_keep_the_portal_accessible(): void
    {
        [$platform] = $this->tenants();
        $branch = $this->branch($platform, 'BGD-OFF', 'فرع بغداد المتوقف');
        $branch->update(['is_active' => false]);
        $owner = $this->user($platform, 'owner', 'disabled-branch-owner');
        $owner->managedBranches()->attach($branch->id, ['access_role' => BranchMembership::OWNER]);

        $this->actingAs($owner)
            ->withHeader('X-Inertia', 'true')
            ->get('/__tests/branch-portal')
            ->assertOk()
            ->assertJsonCount(0, 'props.branches')
            ->assertJsonPath('props.summary.branches', 0);
    }

    public function test_paused_branch_revokes_the_unified_dashboard_session(): void
    {
        [$platform] = $this->tenants();
        $branch = $this->branch($platform, 'BGD-PAUSE', 'فرع بغداد الموقوف');
        $manager = $this->user($platform, 'branch_manager', 'paused-branch-manager');
        $manager->managedBranches()->attach($branch->id, ['access_role' => BranchMembership::MANAGER]);

        $this->actingAs($manager)
            ->get('/dashboard')
            ->assertOk();

        $branch->update(['is_active' => false]);

        $this->actingAs($manager)
            ->get('/dashboard')
            ->assertRedirect('/dashboard/login');

        $this->assertGuest();
    }

    public function test_operational_lists_and_related_profiles_are_limited_to_authorised_branches(): void
    {
        [$platform, $otherTenant] = $this->tenants();
        $visible = $this->branch($platform, 'BGD-SECURE', 'فرع بغداد الآمن');
        $hidden = $this->branch($platform, 'BSR-HIDDEN', 'فرع البصرة المخفي');
        $owner = $this->user($platform, 'owner', 'secure-portal-owner');
        $owner->managedBranches()->attach($visible->id, ['access_role' => BranchMembership::OWNER]);

        $visibleMerchant = $this->user($otherTenant, 'merchant', 'visible-merchant', $visible->id);
        $hiddenMerchant = $this->user($otherTenant, 'merchant', 'hidden-merchant', $hidden->id);
        $visibleCourier = $this->user($otherTenant, 'courier', 'visible-courier', $visible->id);
        $hiddenCourier = $this->user($otherTenant, 'courier', 'hidden-courier', $hidden->id);
        $visibleCourier->update(['is_online' => true]);

        // This cross-branch order is valid for the visible branch, but the
        // portal must not serialise the hidden branch or its courier profile.
        $crossBranchOrder = $this->order($otherTenant, 'ORD-CROSS-SCOPED', $visible);
        $crossBranchOrder->update([
            'destination_branch_id' => $hidden->id,
            'merchant_id' => $visibleMerchant->id,
            'courier_id' => $hiddenCourier->id,
        ]);

        $hiddenOrder = $this->order($otherTenant, 'ORD-HIDDEN-SCOPED', $hidden);
        $hiddenOrder->update([
            'merchant_id' => $hiddenMerchant->id,
            'courier_id' => $hiddenCourier->id,
        ]);

        TenantContext::set($otherTenant);

        $this->actingAs($owner)
            ->withHeader('X-Inertia', 'true')
            ->get('/__tests/branch-portal')
            ->assertOk()
            ->assertJsonMissingPath('props.orders')
            ->assertJsonMissingPath('props.merchants')
            ->assertJsonMissingPath('props.couriers')
            ->assertJsonPath('props.summary.merchants', 1)
            ->assertJsonPath('props.summary.couriers', 1)
            ->assertJsonPath('props.summary.onlineCouriers', 1);

        $this->actingAs($owner)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Partial-Component' => 'Admin/BranchPortal',
                'X-Inertia-Partial-Data' => 'orders,orderCouriers,merchants,couriers',
            ])
            ->get('/__tests/branch-portal')
            ->assertOk()
            ->assertJsonCount(1, 'props.orders')
            ->assertJsonPath('props.orders.0.track_no', 'ORD-CROSS-SCOPED')
            ->assertJsonCount(1, 'props.orders.0.branches')
            ->assertJsonPath('props.orders.0.branches.0.id', $visible->id)
            ->assertJsonPath('props.orders.0.merchant.id', $visibleMerchant->id)
            ->assertJsonPath('props.orders.0.courier', null)
            ->assertJsonCount(1, 'props.orderCouriers')
            ->assertJsonPath('props.orderCouriers.0.id', $visibleCourier->id)
            ->assertJsonCount(1, 'props.merchants')
            ->assertJsonPath('props.merchants.0.id', $visibleMerchant->id)
            ->assertJsonPath('props.merchants.0.branch.id', $visible->id)
            ->assertJsonCount(1, 'props.couriers')
            ->assertJsonPath('props.couriers.0.id', $visibleCourier->id);
    }

    public function test_branch_portal_exposes_only_fresh_last_known_locations_for_its_own_couriers(): void
    {
        [$platform, $otherTenant] = $this->tenants();
        $visible = $this->branch($platform, 'BGD-LOCATION', 'فرع بغداد للمواقع');
        $hidden = $this->branch($platform, 'BSR-LOCATION', 'فرع البصرة للمواقع');
        $owner = $this->user($platform, 'owner', 'location-portal-owner');
        $owner->managedBranches()->attach($visible->id, ['access_role' => BranchMembership::OWNER]);

        $freshCourier = $this->user($otherTenant, 'courier', 'a-fresh-location-courier', $visible->id);
        $freshCourier->update([
            'address' => 'بغداد — الكرادة — شارع 62',
            'current_latitude' => 33.3152412,
            'current_longitude' => 44.3660731,
            'location_accuracy_meters' => 18,
            'location_updated_at' => now(),
        ]);

        $staleCourier = $this->user($otherTenant, 'courier', 'b-stale-location-courier', $visible->id);
        $staleCourier->update([
            'current_latitude' => 33.3200000,
            'current_longitude' => 44.3700000,
            'location_updated_at' => now()->subMinutes(16),
        ]);

        $hiddenCourier = $this->user($otherTenant, 'courier', 'hidden-location-courier', $hidden->id);
        $hiddenCourier->update([
            'current_latitude' => 33.3250000,
            'current_longitude' => 44.3750000,
            'location_updated_at' => now(),
        ]);

        TenantContext::set($otherTenant);

        $this->actingAs($owner)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Partial-Component' => 'Admin/BranchPortal',
                'X-Inertia-Partial-Data' => 'courierLocations',
            ])
            ->get('/__tests/branch-portal')
            ->assertOk()
            ->assertJsonCount(2, 'props.courierLocations')
            ->assertJsonPath('props.courierLocations.0.id', $freshCourier->id)
            ->assertJsonPath('props.courierLocations.0.location.latitude', 33.3152412)
            ->assertJsonPath('props.courierLocations.0.location.longitude', 44.3660731)
            ->assertJsonPath('props.courierLocations.0.location.accuracy_meters', 18)
            ->assertJsonPath('props.courierLocations.0.location.address_label', 'بغداد — الكرادة — شارع 62')
            ->assertJsonPath('props.courierLocations.1.id', $staleCourier->id)
            ->assertJsonPath('props.courierLocations.1.location', null)
            ->assertJsonMissing(['id' => $hiddenCourier->id]);
    }

    public function test_production_portal_route_keeps_owner_isolated_from_admin_and_mobile_api(): void
    {
        [$platform, $otherTenant] = $this->tenants();
        $branch = $this->branch($platform, 'BGD-PORTAL', 'فرع البوابة');
        $owner = $this->user($platform, 'owner', 'real-portal-owner');
        $owner->managedBranches()->attach($branch->id, ['access_role' => BranchMembership::OWNER]);
        $this->order($otherTenant, 'ORD-PORTAL', $branch);

        $this->actingAs($owner)
            ->get('/dashboard/branch')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/BranchPortal')
                ->where('adminBadges', []),
            );

        $dashboardResponse = $this->actingAs($owner)->get('/dashboard');
        $dashboardResponse->assertRedirect();
        $this->assertStringEndsWith('/dashboard/branch', (string) $dashboardResponse->headers->get('Location'));

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/dashboard')->assertForbidden();
    }

    public function test_admin_can_grant_the_same_owner_access_to_more_than_one_branch(): void
    {
        [$platform] = $this->tenants();
        $firstBranch = $this->branch($platform, 'BGD-MULTI-1', 'فرع بغداد الأول');
        $secondBranch = $this->branch($platform, 'BGD-MULTI-2', 'فرع بغداد الثاني');
        $owner = $this->user($platform, 'owner', 'multi-branch-owner');
        $admin = $this->user($platform, 'admin', 'multi-branch-admin');

        $owner->managedBranches()->attach($firstBranch->id, ['access_role' => BranchMembership::OWNER]);

        $this->actingAs($admin)
            ->post('/dashboard/branches/'.$secondBranch->id.'/access', [
                'existing_user_id' => $owner->id,
                'access_role' => 'owner',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('branch_memberships', [
            'branch_id' => $secondBranch->id,
            'user_id' => $owner->id,
            'access_role' => BranchMembership::OWNER,
        ]);
    }

    public function test_branch_owner_can_operate_only_orders_and_people_in_an_explicit_member_branch(): void
    {
        [$platform, $merchantTenant] = $this->tenants();
        $visible = $this->branch($platform, 'BGD-WRITE', 'فرع بغداد التشغيلي');
        $hidden = $this->branch($platform, 'BSR-WRITE', 'فرع البصرة التشغيلي');
        $owner = $this->user($platform, 'owner', 'write-portal-owner');
        $owner->managedBranches()->attach($visible->id, ['access_role' => BranchMembership::OWNER]);
        $visibleOrder = $this->order($merchantTenant, 'ORD-WRITE-VISIBLE', $visible);
        $hiddenOrder = $this->order($merchantTenant, 'ORD-WRITE-HIDDEN', $hidden);
        $visibleMerchant = $this->user($merchantTenant, 'merchant', 'write-visible-merchant', $visible->id);
        $hiddenMerchant = $this->user($merchantTenant, 'merchant', 'write-hidden-merchant', $hidden->id);

        TenantContext::set($merchantTenant);

        $this->actingAs($owner)
            ->post('/__tests/branch-portal/orders/'.$visibleOrder->id.'/status', ['status' => 'cancelled'])
            ->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $visibleOrder->id, 'status' => 'cancelled']);

        $this->actingAs($owner)
            ->post('/__tests/branch-portal/orders/'.$hiddenOrder->id.'/status', ['status' => 'cancelled'])
            ->assertNotFound();
        $this->assertDatabaseHas('orders', ['id' => $hiddenOrder->id, 'status' => 'pending']);

        $this->actingAs($owner)
            ->put('/__tests/branch-portal/users/'.$visibleMerchant->id, [
                'name' => 'تاجر بغداد المحدّث',
                'username' => $visibleMerchant->username,
                'email' => $visibleMerchant->email,
                'phone' => $visibleMerchant->phone,
                'shop_name' => 'متجر بغداد',
                'address' => 'عنوان بغداد',
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $visibleMerchant->id, 'name' => 'تاجر بغداد المحدّث']);

        $this->actingAs($owner)
            ->post('/__tests/branch-portal/users/'.$hiddenMerchant->id.'/status', ['status' => 'suspended'])
            ->assertNotFound();
        $this->assertDatabaseHas('users', ['id' => $hiddenMerchant->id, 'status' => 'active']);
    }

    /** @return array{Tenant, Tenant} */
    private function tenants(): array
    {
        return [
            Tenant::platform(),
            Tenant::create([
                'slug' => 'merchant-branch-portal',
                'name' => 'تاجر الفروع',
                'kind' => 'merchant',
                'status' => 'active',
            ]),
        ];
    }

    private function branch(Tenant $tenant, string $code, string $name): Branch
    {
        return Branch::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'code' => $code,
            'name_ar' => $name,
            'city' => 'بغداد',
            'is_platform_managed' => (int) $tenant->id === (int) Tenant::platform()->id,
            'is_active' => true,
        ]);
    }

    private function user(Tenant $tenant, string $role, string $username, ?int $branchId = null): User
    {
        $user = User::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branchId,
            'name' => $username,
            'username' => $username,
            'email' => $username.'@example.test',
            'phone' => '079'.str_pad((string) (10000000 + User::query()->count()), 8, '0', STR_PAD_LEFT),
            'password' => 'StrongPassword123',
            'role' => $role,
            'status' => 'active',
        ]);

        // The super flag is intentionally not mass-assignable in application
        // code. This fixture models the explicit bootstrap authority.
        if ($role === 'admin') {
            $user->forceFill(['is_super_admin' => true])->save();
        }

        return $user;
    }

    private function order(Tenant $tenant, string $trackNo, Branch $branch): Order
    {
        return Order::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'track_no' => $trackNo,
            'customer_name_ar' => 'زبون اختبار',
            'phone' => '07800000000',
            'address_ar' => 'عنوان اختبار',
            'price' => 25000,
            'status' => 'pending',
            'origin_branch_id' => $branch->id,
            'date' => today(),
        ]);
    }
}
