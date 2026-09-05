<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Cashbox;
use App\Models\CashboxVoucher;
use App\Models\DashboardPermissionProfile;
use App\Models\Order;
use App\Models\Province;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOperationalPermissionGranularityTest extends TestCase
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

    public function test_branch_view_does_not_expose_a_cash_balance_without_the_finance_balance_grant(): void
    {
        $branch = Branch::withoutGlobalScopes()->where('is_active', true)->firstOrFail();
        $branch->update(['cash_balance' => 87_500]);

        $viewer = $this->operator('branch-privacy-viewer', '07941000031', [
            'branches' => ['view'],
        ]);
        $viewerProps = $this->actingAs($viewer)
            ->get('/dashboard/branches')
            ->assertOk()
            ->inertiaPage()['props'];

        $this->assertFalse($viewerProps['canViewBranchCashBalance']);
        $viewerBranch = collect($viewerProps['branches'])->firstWhere('id', $branch->id);
        $this->assertIsArray($viewerBranch);
        $this->assertArrayNotHasKey('cash_balance', $viewerBranch);

        $balanceViewer = $this->operator('branch-balance-viewer', '07941000032', [
            'branches' => ['view'],
            'finance' => ['view', 'view_balances'],
        ]);
        $balanceProps = $this->actingAs($balanceViewer)
            ->get('/dashboard/branches')
            ->assertOk()
            ->inertiaPage()['props'];

        $this->assertTrue($balanceProps['canViewBranchCashBalance']);
        $balanceBranch = collect($balanceProps['branches'])->firstWhere('id', $branch->id);
        $this->assertSame(87_500, data_get($balanceBranch, 'cash_balance'));
    }

    public function test_branch_assignment_operator_loads_only_the_branch_assignment_directory(): void
    {
        $order = Order::withoutGlobalScopes()->whereNull('deleted_at')->firstOrFail();
        $operator = $this->operator('branch-route-operator', '07941000033', [
            'orders' => ['view', 'assign_branches'],
        ]);

        $payload = $this->actingAs($operator)
            ->getJson('/dashboard/orders?directory=assignment&assignment_for='.$order->id)
            ->assertOk()
            ->json();

        $this->assertSame([], $payload['couriers']);
        $this->assertNotEmpty($payload['branches']);
        $this->assertArrayHasKey('id', $payload['branches'][0]);
        $this->assertArrayHasKey('name', $payload['branches'][0]);
    }

    public function test_branch_creation_does_not_grant_access_management_for_existing_branches(): void
    {
        $province = Province::query()
            ->whereNull('tenant_id')
            ->where('is_active', true)
            ->whereNotIn('id', Branch::withoutGlobalScopes()
                ->where('is_platform_managed', true)
                ->pluck('province_id'))
            ->firstOrFail();
        $existingBranch = Branch::withoutGlobalScopes()
            ->where('is_platform_managed', true)
            ->firstOrFail();
        $operator = $this->operator('branch-create-only', '07941000034', [
            'branches' => ['view', 'create'],
        ]);

        $props = $this->actingAs($operator)
            ->get('/dashboard/branches')
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertTrue($props['canCreateBranches']);
        $this->assertFalse($props['canManageBranchAccess']);

        $this->actingAs($operator)
            ->post('/dashboard/branches', [
                'name_ar' => 'فرع إنشاء مستقل',
                'province_id' => $province->id,
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('branches', [
            'name_ar' => 'فرع إنشاء مستقل',
            'province_id' => $province->id,
        ]);

        // A create-only employee may bootstrap the isolated manager for the
        // new branch, but cannot attach or create access accounts elsewhere.
        $this->actingAs($operator)
            ->post("/dashboard/branches/{$existingBranch->id}/access")
            ->assertForbidden();
    }

    public function test_operational_reports_do_not_expose_financial_values_without_the_financial_report_action(): void
    {
        $branch = Branch::withoutGlobalScopes()->where('is_platform_managed', true)->firstOrFail();
        $branch->update(['cash_balance' => 61_500]);

        $viewer = $this->operator('reports-operational-viewer', '07941000035', [
            'reports' => ['view'],
        ]);
        $viewerProps = $this->actingAs($viewer)
            ->get('/dashboard/reports')
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertFalse($viewerProps['canViewFinancialReports']);
        $viewerBranch = collect($viewerProps['branches'])->firstWhere('id', $branch->id);
        $this->assertArrayNotHasKey('cash_balance', $viewerBranch);
        $this->assertArrayNotHasKey('delivered_value', $viewerProps['kpis']);
        $this->assertArrayNotHasKey('fees', $viewerProps['kpis']);
        $this->assertArrayNotHasKey('cash_collected', $viewerProps['kpis']);
        $this->assertArrayNotHasKey('pending_settlements', $viewerProps['kpis']);
        $this->assertArrayNotHasKey('value', $viewerProps['merchants'][0]);

        $balanceViewer = $this->operator('reports-financial-viewer', '07941000036', [
            'reports' => ['view', 'view_financial'],
        ]);
        $balanceProps = $this->actingAs($balanceViewer)
            ->get('/dashboard/reports')
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertTrue($balanceProps['canViewFinancialReports']);
        $balanceBranch = collect($balanceProps['branches'])->firstWhere('id', $branch->id);
        $this->assertSame(61_500, data_get($balanceBranch, 'cash_balance'));
        $this->assertArrayHasKey('delivered_value', $balanceProps['kpis']);
        $this->assertArrayHasKey('value', $balanceProps['merchants'][0]);
    }

    public function test_cashbox_directory_get_is_read_only_for_a_view_only_operator(): void
    {
        $viewer = $this->operator('cashbox-read-only-viewer', '07941000039', [
            'cashboxes' => ['view'],
        ]);

        // The demo setup creates a platform branch but intentionally does
        // not materialise its operating cashbox. Loading the directory must
        // not turn that read request into a firstOrCreate side effect.
        $before = Cashbox::withoutGlobalScopes()->count();
        $this->assertFalse(Cashbox::withoutGlobalScopes()
            ->where('tenant_id', Tenant::platform()->id)
            ->where('kind', 'vault')
            ->exists());

        $props = $this->actingAs($viewer)
            ->get('/dashboard/cashboxes')
            ->assertOk()
            ->inertiaPage()['props'];

        $this->assertSame($before, Cashbox::withoutGlobalScopes()->count());
        $this->assertFalse(Cashbox::withoutGlobalScopes()
            ->where('tenant_id', Tenant::platform()->id)
            ->where('kind', 'vault')
            ->exists());
        $this->assertFalse($props['canViewCashboxBalances']);
        $this->assertFalse($props['canViewCashboxLedger']);
    }

    public function test_cashbox_balance_and_ledger_reads_are_independently_scoped(): void
    {
        $platform = Tenant::platform();
        $admin = User::withoutGlobalScopes()->where('role', 'admin')->firstOrFail();
        $cashbox = Cashbox::withoutGlobalScopes()->create([
            'tenant_id' => $platform->id,
            'kind' => 'vault',
            'name_ar' => 'خزنة اختبار الصلاحيات',
            'name_en' => 'Permission test vault',
            'balance' => 987_000,
            'is_active' => true,
        ]);
        $voucher = CashboxVoucher::withoutGlobalScopes()->create([
            'tenant_id' => $platform->id,
            'cashbox_id' => $cashbox->id,
            'actor_id' => $admin->id,
            'type' => 'courier_handover',
            'direction' => 1,
            'amount' => 12_500,
            'reference' => 'CASHBOX-PERMISSION-TEST',
            'note' => 'سجل اختبار الصلاحيات',
            'occurred_at' => now(),
        ]);

        $directoryViewer = $this->operator('cashbox-directory-viewer', '07941000040', [
            'cashboxes' => ['view'],
        ]);
        $directoryProps = $this->actingAs($directoryViewer)
            ->get('/dashboard/cashboxes')
            ->assertOk()
            ->inertiaPage()['props'];
        $directoryBox = collect($directoryProps['cashboxes'])->firstWhere('id', $cashbox->id);

        $this->assertFalse($directoryProps['canViewCashboxBalances']);
        $this->assertFalse($directoryProps['canViewCashboxLedger']);
        $this->assertIsArray($directoryBox);
        $this->assertArrayNotHasKey('balance', $directoryBox);
        $this->assertArrayNotHasKey('historical_book_balance', $directoryBox);
        $this->assertArrayNotHasKey('balance_source', $directoryBox);
        $this->assertSame([], $directoryProps['vouchers']);
        $this->assertArrayNotHasKey('balance', $directoryProps['summary']);
        $this->assertArrayNotHasKey('branch_balance', $directoryProps['summary']);
        $this->assertArrayNotHasKey('vault_balance', $directoryProps['summary']);
        $this->assertArrayNotHasKey('bank_balance', $directoryProps['summary']);

        $balanceViewer = $this->operator('cashbox-balance-viewer', '07941000041', [
            'cashboxes' => ['view', 'view_balances'],
        ]);
        $balanceProps = $this->actingAs($balanceViewer)
            ->get('/dashboard/cashboxes')
            ->assertOk()
            ->inertiaPage()['props'];
        $balanceBox = collect($balanceProps['cashboxes'])->firstWhere('id', $cashbox->id);

        $this->assertTrue($balanceProps['canViewCashboxBalances']);
        $this->assertFalse($balanceProps['canViewCashboxLedger']);
        $this->assertSame(12_500, $balanceBox['balance']);
        $this->assertSame(987_000, $balanceBox['historical_book_balance']);
        $this->assertSame(12_500, $balanceProps['summary']['balance']);
        $this->assertSame([], $balanceProps['vouchers']);

        $ledgerViewer = $this->operator('cashbox-ledger-viewer', '07941000042', [
            'cashboxes' => ['view', 'view_ledger'],
        ]);
        $ledgerProps = $this->actingAs($ledgerViewer)
            ->get('/dashboard/cashboxes')
            ->assertOk()
            ->inertiaPage()['props'];
        $ledgerBox = collect($ledgerProps['cashboxes'])->firstWhere('id', $cashbox->id);
        $ledgerVoucher = collect($ledgerProps['vouchers'])->firstWhere('id', $voucher->id);

        $this->assertFalse($ledgerProps['canViewCashboxBalances']);
        $this->assertTrue($ledgerProps['canViewCashboxLedger']);
        $this->assertArrayNotHasKey('balance', $ledgerBox);
        $this->assertArrayNotHasKey('historical_book_balance', $ledgerBox);
        $this->assertArrayNotHasKey('balance', $ledgerProps['summary']);
        $this->assertIsArray($ledgerVoucher);
        $this->assertSame(12_500, $ledgerVoucher['amount']);
        $this->assertSame('CASHBOX-PERMISSION-TEST', $ledgerVoucher['reference']);
    }

    public function test_order_operations_do_not_expose_monetary_values_without_the_orders_financial_read_action(): void
    {
        $order = Order::withoutGlobalScopes()->whereNull('deleted_at')->firstOrFail();
        $order->update(['price' => 73_000, 'fee' => 4_500]);

        $viewer = $this->operator('orders-operational-viewer', '07941000037', [
            'orders' => ['view'],
        ]);
        $viewerProps = $this->actingAs($viewer)
            ->get('/dashboard/orders?q='.$order->track_no)
            ->assertOk()
            ->inertiaPage()['props'];

        $this->assertFalse($viewerProps['canViewOrderFinancialDetails']);
        $this->assertFalse($viewerProps['canViewOrderFinancialSummary']);
        $viewerOrder = collect($viewerProps['orders']['data'])->firstWhere('id', $order->id);
        $this->assertIsArray($viewerOrder);
        $this->assertArrayNotHasKey('price', $viewerOrder);
        $this->assertArrayNotHasKey('fee', $viewerOrder);
        $this->assertArrayNotHasKey('financial', $viewerOrder);
        $this->assertArrayNotHasKey('amount', $viewerProps['summary']['all']);
        $this->assertArrayNotHasKey('order_value', $viewerProps['summary']['all']);

        $viewerDetail = $this->actingAs($viewer)
            ->getJson('/dashboard/orders?detail='.$order->id)
            ->assertOk()
            ->json('order');
        $this->assertArrayNotHasKey('price', $viewerDetail);
        $this->assertArrayNotHasKey('fee', $viewerDetail);
        $this->assertArrayNotHasKey('financial', $viewerDetail);
        $this->assertArrayNotHasKey('return_fee', $viewerDetail);

        $financialViewer = $this->operator('orders-financial-viewer', '07941000038', [
            'orders' => ['view', 'view_financial'],
        ]);
        $financialProps = $this->actingAs($financialViewer)
            ->get('/dashboard/orders?q='.$order->track_no)
            ->assertOk()
            ->inertiaPage()['props'];
        $financialOrder = collect($financialProps['orders']['data'])->firstWhere('id', $order->id);

        $this->assertTrue($financialProps['canViewOrderFinancialDetails']);
        $this->assertTrue($financialProps['canViewOrderFinancialSummary']);
        $this->assertSame(73_000, $financialOrder['price']);
        $this->assertSame(4_500, $financialOrder['fee']);
        $this->assertSame(73_000, $financialOrder['financial']['order_value']);
        $this->assertArrayHasKey('amount', $financialProps['summary']['all']);
    }

    /** @param array<string, array<int, string>> $permissions */
    private function operator(string $username, string $phone, array $permissions): User
    {
        $profile = DashboardPermissionProfile::create([
            'name' => $username,
            'permissions' => $permissions,
        ]);
        $admin = User::withoutGlobalScopes()->where('role', 'admin')->firstOrFail();

        return User::create([
            'tenant_id' => $admin->tenant_id,
            'name' => $username,
            'username' => $username,
            'email' => $username.'@example.test',
            'phone' => $phone,
            'password' => 'Password123!',
            'role' => 'admin',
            'status' => 'active',
            'permission_profile_id' => $profile->id,
            'is_super_admin' => false,
        ]);
    }
}
