<?php

namespace Tests\Feature;

use App\Models\DashboardPermissionProfile;
use App\Models\FinanceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFinanceReadPermissionTest extends TestCase
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

    public function test_page_access_and_an_approver_only_receive_the_data_needed_to_open_or_decide_requests(): void
    {
        $courier = User::withoutGlobalScopes()->where('role', 'courier')->firstOrFail();
        $pending = $this->request($courier, 'FIN-READ-PENDING', FinanceRequest::PENDING);
        $approved = $this->request($courier, 'FIN-READ-APPROVED', FinanceRequest::APPROVED);
        $this->transaction($courier, 'collected', 'TX-READ-PRIVATE');

        $pageOnly = $this->operator('finance-page-only', '07940101001', ['view']);
        $pageProps = $this->actingAs($pageOnly)
            ->get('/dashboard/finance')
            ->assertOk()
            ->inertiaPage()['props'];

        $this->assertFalse($pageProps['canViewFinanceRequests']);
        $this->assertFalse($pageProps['canViewFinanceLedger']);
        $this->assertFalse($pageProps['canViewFinanceSummary']);
        $this->assertFalse($pageProps['canViewFinanceBalances']);
        foreach (['pendingRequests', 'requests', 'transactions', 'summary', 'balanceSummary', 'branches', 'accounts'] as $prop) {
            $this->assertArrayNotHasKey($prop, $pageProps);
        }
        $this->actingAs($pageOnly)->getJson('/dashboard/finance?detail=delivery_collections')->assertForbidden();
        $this->actingAs($pageOnly)->getJson('/dashboard/finance?detail=courier_balances')->assertForbidden();

        $approver = $this->operator('finance-pending-approver', '07940101002', ['view', 'approve']);
        $approverProps = $this->actingAs($approver)
            ->get('/dashboard/finance')
            ->assertOk()
            ->inertiaPage()['props'];

        $this->assertTrue($approverProps['canApproveFinance']);
        $this->assertArrayHasKey('pendingRequests', $approverProps);
        $this->assertTrue(collect($approverProps['pendingRequests'])->contains('id', $pending->id));
        $this->assertFalse(collect($approverProps['pendingRequests'])->contains('id', $approved->id));
        foreach (['requests', 'transactions', 'summary', 'balanceSummary', 'accounts'] as $prop) {
            $this->assertArrayNotHasKey($prop, $approverProps);
        }
        $this->assertArrayHasKey('branches', $approverProps);
        $this->assertArrayNotHasKey('cash_balance', $approverProps['branches'][0]);
        $this->actingAs($approver)->getJson('/dashboard/finance?detail=qi_topups')->assertForbidden();
    }

    public function test_each_explicit_finance_reader_receives_only_its_own_surface_and_card_json_is_server_guarded(): void
    {
        $courier = User::withoutGlobalScopes()->where('role', 'courier')->firstOrFail();
        $pending = $this->request($courier, 'FIN-READ-HISTORY-PENDING', FinanceRequest::PENDING);
        $approved = $this->request($courier, 'FIN-READ-HISTORY-APPROVED', FinanceRequest::APPROVED);
        $ledger = $this->transaction($courier, 'collected', 'TX-READ-LEDGER');

        $requestReader = $this->operator('finance-request-reader', '07940101003', ['view', 'view_requests']);
        $requestProps = $this->actingAs($requestReader)
            ->get('/dashboard/finance')
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertTrue($requestProps['canViewFinanceRequests']);
        $this->assertTrue(collect($requestProps['requests'])->contains('id', $pending->id));
        $this->assertTrue(collect($requestProps['requests'])->contains('id', $approved->id));
        foreach (['pendingRequests', 'transactions', 'summary', 'balanceSummary', 'branches', 'accounts'] as $prop) {
            $this->assertArrayNotHasKey($prop, $requestProps);
        }
        $this->actingAs($requestReader)->getJson('/dashboard/finance?detail=delivery_collections')->assertForbidden();

        $ledgerReader = $this->operator('finance-ledger-reader', '07940101004', ['view', 'view_ledger']);
        $ledgerProps = $this->actingAs($ledgerReader)
            ->get('/dashboard/finance')
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertTrue($ledgerProps['canViewFinanceLedger']);
        $this->assertTrue(collect($ledgerProps['transactions'])->contains('id', $ledger->id));
        foreach (['pendingRequests', 'requests', 'summary', 'balanceSummary', 'branches', 'accounts'] as $prop) {
            $this->assertArrayNotHasKey($prop, $ledgerProps);
        }
        $this->actingAs($ledgerReader)->getJson('/dashboard/finance?detail=qi_topups')->assertForbidden();

        $summaryReader = $this->operator('finance-summary-reader', '07940101005', ['view', 'view_summary']);
        $summaryProps = $this->actingAs($summaryReader)
            ->get('/dashboard/finance')
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertTrue($summaryProps['canViewFinanceSummary']);
        $this->assertArrayHasKey('summary', $summaryProps);
        $this->assertArrayHasKey('delivery_collections', $summaryProps['summary']['cards']);
        foreach (['pendingRequests', 'requests', 'transactions', 'balanceSummary', 'branches', 'accounts'] as $prop) {
            $this->assertArrayNotHasKey($prop, $summaryProps);
        }
        $this->actingAs($summaryReader)
            ->getJson('/dashboard/finance?detail=delivery_collections')
            ->assertOk()
            ->assertJsonPath('detail', 'delivery_collections');
        $this->actingAs($summaryReader)
            ->getJson('/dashboard/finance?detail=courier_balances')
            ->assertForbidden();

        $balanceReader = $this->operator('finance-balance-reader', '07940101006', ['view', 'view_balances']);
        $balanceProps = $this->actingAs($balanceReader)
            ->get('/dashboard/finance')
            ->assertOk()
            ->inertiaPage()['props'];
        $this->assertTrue($balanceProps['canViewFinanceBalances']);
        $this->assertArrayHasKey('balanceSummary', $balanceProps);
        $this->assertArrayHasKey('courier_balances', $balanceProps['balanceSummary']);
        $this->assertArrayHasKey('wallet_balance', $balanceProps['accounts'][0]);
        foreach (['pendingRequests', 'requests', 'transactions', 'summary'] as $prop) {
            $this->assertArrayNotHasKey($prop, $balanceProps);
        }
        $this->actingAs($balanceReader)
            ->getJson('/dashboard/finance?detail=courier_balances')
            ->assertOk()
            ->assertJsonPath('detail', 'courier_balances');
        $this->actingAs($balanceReader)->getJson('/dashboard/finance?detail=delivery_collections')->assertForbidden();
    }

    /** @param array<int, string> $permissions */
    private function operator(string $username, string $phone, array $permissions): User
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
            'phone' => $phone,
            'password' => 'Password123!',
            'role' => 'admin',
            'status' => 'active',
            'permission_profile_id' => $profile->id,
            'is_super_admin' => false,
        ]);
    }

    private function request(User $courier, string $reference, string $status): FinanceRequest
    {
        return FinanceRequest::withoutGlobalScopes()->create([
            'tenant_id' => $courier->tenant_id,
            'user_id' => $courier->id,
            'type' => FinanceRequest::QI_TOPUP,
            'amount' => 10_000,
            'approved_amount' => $status === FinanceRequest::APPROVED ? 10_000 : null,
            'status' => $status,
            'reference' => $reference,
            'processed_at' => $status === FinanceRequest::APPROVED ? now() : null,
        ]);
    }

    private function transaction(User $courier, string $type, string $reference): Transaction
    {
        return Transaction::withoutGlobalScopes()->create([
            'tenant_id' => $courier->tenant_id,
            'user_id' => $courier->id,
            'type' => $type,
            'amount' => 7_000,
            'direction' => 1,
            'ref' => $reference,
            'date' => today(),
        ]);
    }
}
