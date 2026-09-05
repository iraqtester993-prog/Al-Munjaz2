<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DashboardPermissionProfile;
use App\Models\User;
use App\Services\FinanceRequestService;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFinanceDirectoryPermissionTest extends TestCase
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

    public function test_finance_approver_receives_only_the_active_branch_directory(): void
    {
        [$activeBranch, $inactiveBranch] = $this->branchFixtures();
        $approver = $this->financeOperator('finance-directory-approver', ['view', 'approve']);

        $props = $this->actingAs($approver)
            ->get('/dashboard/finance')
            ->assertOk()
            ->inertiaPage()['props'];

        $this->assertTrue($props['canApproveFinance']);
        $this->assertFalse($props['canRecordSettlement']);
        $this->assertFalse($props['canViewFinanceBalances']);
        $this->assertArrayHasKey('branches', $props);
        $this->assertArrayNotHasKey('accounts', $props);
        $this->assertArrayNotHasKey('summary', $props);
        $this->assertArrayNotHasKey('balanceSummary', $props);

        $branches = collect($props['branches']);
        $activeDirectoryEntry = $branches->firstWhere('id', $activeBranch->id);

        foreach ($branches as $branch) {
            $this->assertSame(['id', 'name', 'city'], array_keys($branch));
        }
        $this->assertSame([
            'id' => $activeBranch->id,
            'name' => $activeBranch->name_ar,
            'city' => $activeBranch->city,
        ], $activeDirectoryEntry);
        $this->assertFalse($branches->contains('id', $inactiveBranch->id));
    }

    public function test_settlement_operator_gets_minimal_directories_while_balance_viewer_keeps_full_details(): void
    {
        [$activeBranch, $inactiveBranch] = $this->branchFixtures();
        $merchant = User::withoutGlobalScopes()->where('role', 'merchant')->where('status', 'active')->firstOrFail();
        $courier = User::withoutGlobalScopes()->where('role', 'courier')->where('status', 'active')->firstOrFail();
        $merchant->wallet()->updateOrCreate([], ['balance' => 17_000, 'budget' => 3_000, 'budget_balance' => 2_000]);
        $courier->wallet()->updateOrCreate([], ['balance' => 47_000, 'budget' => 23_000, 'budget_balance' => 19_000]);

        $inactiveMerchant = User::create([
            'tenant_id' => $merchant->tenant_id,
            'name' => 'تاجر تسوية غير نشط',
            'username' => 'inactive-finance-directory-merchant',
            'phone' => '07940000431',
            'password' => 'Password123!',
            'role' => 'merchant',
            'status' => 'inactive',
        ]);
        $settlementOperator = $this->financeOperator('finance-directory-settlement', ['view', 'record_settlement']);

        $settlementProps = $this->actingAs($settlementOperator)
            ->get('/dashboard/finance')
            ->assertOk()
            ->inertiaPage()['props'];

        $this->assertTrue($settlementProps['canRecordSettlement']);
        $this->assertFalse($settlementProps['canViewFinanceBalances']);
        $this->assertArrayNotHasKey('summary', $settlementProps);
        $this->assertArrayNotHasKey('balanceSummary', $settlementProps);

        $settlementBranches = collect($settlementProps['branches']);
        $settlementAccounts = collect($settlementProps['accounts']);
        foreach ($settlementBranches as $branch) {
            $this->assertSame(['id', 'name', 'city'], array_keys($branch));
        }
        foreach ($settlementAccounts as $account) {
            $this->assertSame(['id', 'name', 'phone', 'role'], array_keys($account));
        }
        $this->assertSame([
            'id' => $activeBranch->id,
            'name' => $activeBranch->name_ar,
            'city' => $activeBranch->city,
        ], $settlementBranches->firstWhere('id', $activeBranch->id));
        $this->assertFalse($settlementBranches->contains('id', $inactiveBranch->id));
        $this->assertSame([
            'id' => $merchant->id,
            'name' => $merchant->name,
            'phone' => $merchant->phone,
            'role' => 'merchant',
        ], $settlementAccounts->firstWhere('id', $merchant->id));
        $this->assertSame([
            'id' => $courier->id,
            'name' => $courier->name,
            'phone' => $courier->phone,
            'role' => 'courier',
        ], $settlementAccounts->firstWhere('id', $courier->id));
        $this->assertFalse($settlementAccounts->contains('id', $inactiveMerchant->id));

        $balanceViewer = $this->financeOperator('finance-directory-balance-viewer', ['view', 'view_balances']);
        $balanceProps = $this->actingAs($balanceViewer)
            ->get('/dashboard/finance')
            ->assertOk()
            ->inertiaPage()['props'];

        $this->assertTrue($balanceProps['canViewFinanceBalances']);
        $this->assertSame(
            (int) Branch::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)->sum('cash_balance'),
            $balanceProps['balanceSummary']['branch_cash'],
        );
        $this->assertArrayHasKey('courier_balances', $balanceProps['balanceSummary']);
        $this->assertSame([
            'id' => $activeBranch->id,
            'name' => $activeBranch->name_ar,
            'city' => $activeBranch->city,
            'cash_balance' => 91_000,
        ], collect($balanceProps['branches'])->firstWhere('id', $activeBranch->id));
        $finance = app(FinanceRequestService::class);
        $this->assertSame([
            'id' => $courier->id,
            'name' => $courier->name,
            'phone' => $courier->phone,
            'role' => 'courier',
            'wallet_balance' => 47_000,
            'budget' => 23_000,
            'budget_balance' => 19_000,
            'cash_on_hand' => $finance->cashOnHand($courier->id),
            'collections_total' => $finance->collectionsTotal($courier),
        ], collect($balanceProps['accounts'])->firstWhere('id', $courier->id));
    }

    /** @return array{Branch, Branch} */
    private function branchFixtures(): array
    {
        $activeBranch = Branch::withoutGlobalScopes()->where('is_active', true)->firstOrFail();
        $activeBranch->update(['cash_balance' => 91_000]);

        $inactiveBranch = Branch::withoutGlobalScopes()->create([
            'tenant_id' => $activeBranch->tenant_id,
            'name_ar' => 'فرع مالي غير نشط',
            'city' => 'بغداد',
            'cash_balance' => 999_000,
            'is_active' => false,
        ]);

        return [$activeBranch->fresh(), $inactiveBranch];
    }

    /** @param array<int, string> $permissions */
    private function financeOperator(string $username, array $permissions): User
    {
        $profile = DashboardPermissionProfile::create([
            'name' => $username,
            'permissions' => ['finance' => $permissions],
        ]);
        $admin = User::withoutGlobalScopes()->where('role', 'admin')->firstOrFail();

        return User::create([
            'tenant_id' => $admin->tenant_id,
            'name' => $username,
            'username' => $username,
            'email' => $username.'@example.test',
            'phone' => match ($username) {
                'finance-directory-approver' => '07940000441',
                'finance-directory-settlement' => '07940000442',
                default => '07940000443',
            },
            'password' => 'Password123!',
            'role' => 'admin',
            'status' => 'active',
            'permission_profile_id' => $profile->id,
            'is_super_admin' => false,
        ]);
    }
}
