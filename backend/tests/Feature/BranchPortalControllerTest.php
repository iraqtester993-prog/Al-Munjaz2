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
            ->assertJsonPath('props.summary.branches', 1);
    }

    public function test_branch_manager_can_use_only_explicit_manager_memberships(): void
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
            ->assertOk()
            ->assertJsonPath('component', 'Admin/BranchPortal')
            ->assertJsonCount(1, 'props.branches')
            ->assertJsonCount(1, 'props.recentOrders')
            ->assertJsonPath('props.summary.branches', 1);
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
        return User::create([
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
