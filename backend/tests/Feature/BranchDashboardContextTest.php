<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchMembership;
use App\Models\Order;
use App\Models\Province;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchDashboardContext;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchDashboardContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::clear();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_super_admin_keeps_an_unrestricted_branch_dashboard_scope(): void
    {
        $admin = $this->user('admin', 'scope-super-admin');
        $admin->forceFill(['is_super_admin' => true])->save();

        $scope = app(BranchDashboardContext::class)->scopeFor($admin);

        $this->assertTrue($scope->isSuperAdmin());
        $this->assertTrue($scope->isAvailable());
        $this->assertFalse($scope->hasBranchScope());
    }

    public function test_primary_assignment_restricts_people_and_orders_to_one_branch_only(): void
    {
        $first = $this->branch('BGD-SCOPE', 'بغداد');
        $second = $this->branch('BSR-SCOPE', 'البصرة');
        $manager = $this->user('branch_manager', 'scope-branch-manager');
        $context = app(BranchDashboardContext::class);

        $context->assignPrimaryMembership($manager, $first);
        $context->assignPrimaryMembership($manager, $second);

        $scope = $context->scopeFor($manager->fresh());
        $visibleMerchant = $this->user('merchant', 'scope-visible-merchant', $second->id);
        $hiddenMerchant = $this->user('merchant', 'scope-hidden-merchant', $first->id);
        $visibleOrder = $this->order('SCOPE-VISIBLE', $second);
        $destinationVisibleOrder = $this->order('SCOPE-DESTINATION', $first, $second);
        $hiddenOrder = $this->order('SCOPE-HIDDEN', $first);

        $this->assertTrue($scope->hasBranchScope());
        $this->assertSame($second->id, $scope->branchId());
        $this->assertTrue($scope->allowsBranch($second));
        $this->assertFalse($scope->allowsBranch($first));
        $this->assertDatabaseHas('branch_memberships', [
            'branch_id' => $second->id,
            'user_id' => $manager->id,
            'access_role' => BranchMembership::MANAGER,
            'is_primary' => true,
            'primary_user_id' => $manager->id,
        ]);
        $this->assertDatabaseMissing('branch_memberships', [
            'branch_id' => $first->id,
            'user_id' => $manager->id,
            'is_primary' => true,
        ]);

        $merchantIds = $scope
            ->restrictUsers(User::query()->where('role', 'merchant'))
            ->pluck('id')
            ->all();
        $orderIds = $scope
            ->restrictOrders(Order::withoutGlobalScope(TenantScope::class))
            ->pluck('id')
            ->all();

        $this->assertSame([$visibleMerchant->id], $merchantIds);
        $this->assertEqualsCanonicalizing([$visibleOrder->id, $destinationVisibleOrder->id], $orderIds);
        $this->assertNotContains($hiddenMerchant->id, $merchantIds);
        $this->assertNotContains($hiddenOrder->id, $orderIds);
    }

    public function test_multiple_legacy_memberships_are_denied_until_one_is_marked_primary(): void
    {
        $first = $this->branch('KRB-SCOPE', 'كربلاء');
        $second = $this->branch('MYS-SCOPE', 'ميسان');
        $manager = $this->user('branch_manager', 'scope-ambiguous-manager');

        $first->members()->attach($manager->id, ['access_role' => BranchMembership::MANAGER]);
        $second->members()->attach($manager->id, ['access_role' => BranchMembership::MANAGER]);

        $scope = app(BranchDashboardContext::class)->scopeFor($manager);

        $this->assertTrue($scope->requiresBranchScope());
        $this->assertFalse($scope->isAvailable());
        $this->assertFalse($scope->hasBranchScope());
    }

    public function test_primary_membership_migration_backfills_the_legacy_branch_pointer_and_valid_fallback(): void
    {
        $first = $this->branch('WAS-BACKFILL', 'واسط');
        $preferred = $this->branch('BJL-BACKFILL', 'بابل');
        $fallback = $this->branch('MTH-BACKFILL', 'المثنى');

        $managerWithLegacyPointer = $this->user('branch_manager', 'scope-legacy-pointer', $preferred->id);
        $managerWithoutLegacyPointer = $this->user('branch_manager', 'scope-legacy-fallback');

        // These records represent the state immediately before the scope
        // constraints migration: memberships are valid, but none can yet be
        // marked primary. The old users.branch_id assignment remains the
        // strongest available indication of the intended dashboard boundary.
        $first->members()->attach($managerWithLegacyPointer->id, ['access_role' => BranchMembership::MANAGER]);
        $preferred->members()->attach($managerWithLegacyPointer->id, ['access_role' => BranchMembership::MANAGER]);
        $first->members()->attach($managerWithoutLegacyPointer->id, ['access_role' => BranchMembership::MANAGER]);
        $fallback->members()->attach($managerWithoutLegacyPointer->id, ['access_role' => BranchMembership::MANAGER]);

        $migration = require database_path('migrations/2026_09_04_120000_add_branch_dashboard_scope_constraints.php');

        // RefreshDatabase has already applied every migration. Rewind only
        // this migration so the test executes its public up() path against
        // genuine legacy rows rather than calling a private helper directly.
        $migration->down();
        $migration->up();

        $this->assertDatabaseHas('branch_memberships', [
            'branch_id' => $preferred->id,
            'user_id' => $managerWithLegacyPointer->id,
            'access_role' => BranchMembership::MANAGER,
            'is_primary' => true,
            'primary_user_id' => $managerWithLegacyPointer->id,
        ]);
        $this->assertDatabaseMissing('branch_memberships', [
            'branch_id' => $first->id,
            'user_id' => $managerWithLegacyPointer->id,
            'is_primary' => true,
        ]);

        $this->assertDatabaseHas('branch_memberships', [
            'branch_id' => $first->id,
            'user_id' => $managerWithoutLegacyPointer->id,
            'access_role' => BranchMembership::MANAGER,
            'is_primary' => true,
            'primary_user_id' => $managerWithoutLegacyPointer->id,
        ]);
        $this->assertDatabaseMissing('branch_memberships', [
            'branch_id' => $fallback->id,
            'user_id' => $managerWithoutLegacyPointer->id,
            'is_primary' => true,
        ]);
    }

    public function test_only_one_active_platform_branch_can_claim_a_province(): void
    {
        $province = Province::create([
            'name_ar' => 'ذي قار',
            'name_en' => 'Dhi Qar',
            'name_ku' => 'ذی قار',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $platform = Tenant::platform();

        $first = Branch::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $platform->id,
            'is_platform_managed' => true,
            'code' => 'DHI-ONE',
            'name_ar' => 'فرع ذي قار الأول',
            'province_id' => $province->id,
            'is_active' => true,
        ]);

        $this->assertSame($province->id, (int) $first->active_platform_province_id);

        $this->expectException(QueryException::class);

        Branch::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $platform->id,
            'is_platform_managed' => true,
            'code' => 'DHI-TWO',
            'name_ar' => 'فرع ذي قار الثاني',
            'province_id' => $province->id,
            'is_active' => true,
        ]);
    }

    private function branch(string $code, string $provinceName): Branch
    {
        $province = Province::create([
            'name_ar' => $provinceName,
            'name_en' => $provinceName,
            'name_ku' => $provinceName,
            'sort_order' => Province::query()->count() + 1,
            'is_active' => true,
        ]);

        return Branch::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => Tenant::platform()->id,
            'is_platform_managed' => true,
            'code' => $code,
            'name_ar' => 'فرع '.$provinceName,
            'province_id' => $province->id,
            'is_active' => true,
        ]);
    }

    private function user(string $role, string $username, ?int $branchId = null): User
    {
        return User::create([
            'tenant_id' => Tenant::platform()->id,
            'branch_id' => $branchId,
            'name' => $username,
            'username' => $username,
            'email' => $username.'@example.test',
            'phone' => '079'.str_pad((string) (10000000 + User::query()->count()), 8, '0', STR_PAD_LEFT),
            'password' => 'StrongPassword123!',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function order(string $trackNo, Branch $origin, ?Branch $destination = null): Order
    {
        return Order::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => Tenant::platform()->id,
            'track_no' => $trackNo,
            'customer_name_ar' => 'عميل اختبار',
            'phone' => '07800000000',
            'address_ar' => 'عنوان اختبار',
            'price' => 15000,
            'status' => 'pending',
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination?->id,
            'date' => today(),
        ]);
    }
}
