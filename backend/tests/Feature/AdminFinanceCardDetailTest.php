<?php

namespace Tests\Feature;

use App\Models\DashboardPermissionProfile;
use App\Models\FinanceRequest;
use App\Models\Scopes\TenantScope;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminFinanceCardDetailTest extends TestCase
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

    public function test_finance_cards_use_qi_balances_net_delivery_collections_and_qi_topups_with_bounded_details(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $courier = User::where('role', 'courier')->firstOrFail();
        $courier->wallet()->updateOrCreate([], [
            'balance' => 99_000,
            'budget' => 70_000,
            'budget_balance' => 31_000,
        ]);

        // A retired operational role must not affect the direct-courier Qi
        // balance card even if it has a very large old wallet balance.
        $legacyCourier = User::create([
            'tenant_id' => $courier->tenant_id,
            'name' => 'مندوب قديم لا يدخل في الرصيد',
            'username' => 'legacy-finance-card',
            'phone' => '07940000121',
            'password' => 'Password123!',
            'role' => 'pickup_courier',
            'status' => 'active',
            'vehicle' => 'bike',
        ]);
        $legacyCourier->wallet()->create([
            'balance' => 1_000_000,
            'budget' => 500_000,
            'budget_balance' => 500_000,
        ]);

        $collection = $this->transaction($courier, 'collected', 3_000, 1, 'ORD-FIN-CARD-1');
        $cashHandover = $this->transaction($courier, FinanceRequest::CASH_HANDOVER, 80_000, -1, 'FIN-HANDOVER-CARD');

        $qiRequest = FinanceRequest::withoutGlobalScopes()->create([
            'tenant_id' => $courier->tenant_id,
            'user_id' => $courier->id,
            'type' => FinanceRequest::QI_TOPUP,
            'amount' => 8_000,
            'approved_amount' => 8_000,
            'status' => FinanceRequest::APPROVED,
            'reference' => 'FIN-QI-CARD-1',
            'external_reference' => 'QI-CARD-REFERENCE-1',
            'processed_by' => $admin->id,
            'processed_at' => now(),
        ]);
        $qiTopup = $this->transaction($courier, FinanceRequest::QI_TOPUP, 8_000, 1, 'FIN-QI-CARD-1', $qiRequest->id);

        $expectedBalances = $this->courierBalanceSummary();
        $expectedCollections = $this->transactionSummary('collected');
        $expectedQiTopups = $this->transactionSummary(FinanceRequest::QI_TOPUP);

        $this->actingAs($admin)
            ->get('/dashboard/finance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Finance')
                ->where('balanceSummary.courier_balances', $expectedBalances)
                ->where('summary.cards.delivery_collections', $expectedCollections)
                ->where('summary.cards.qi_topups', $expectedQiTopups)
                // Existing flat data remains available to old Finance views.
                ->where('summary.qi_topups', $expectedQiTopups['amount']));

        $collectionPayload = $this->actingAs($admin)
            ->getJson('/dashboard/finance?detail=delivery_collections')
            ->assertOk()
            ->assertJsonPath('detail', 'delivery_collections')
            ->assertJsonPath('limit', 100)
            ->json();
        $this->assertTrue(collect($collectionPayload['rows'])->contains(
            fn (array $row) => $row['id'] === $collection->id
                && $row['amount'] === 3_000
                && $row['reference'] === 'ORD-FIN-CARD-1'
        ));
        $this->assertFalse(collect($collectionPayload['rows'])->contains('id', $cashHandover->id));

        $qiPayload = $this->actingAs($admin)
            ->getJson('/dashboard/finance?detail=qi_topups')
            ->assertOk()
            ->assertJsonPath('detail', 'qi_topups')
            ->json();
        $this->assertTrue(collect($qiPayload['rows'])->contains(
            fn (array $row) => $row['id'] === $qiTopup->id
                && $row['external_reference'] === 'QI-CARD-REFERENCE-1'
        ));

        $balancePayload = $this->actingAs($admin)
            ->getJson('/dashboard/finance?detail=courier_balances')
            ->assertOk()
            ->assertJsonPath('detail', 'courier_balances')
            ->json();
        $this->assertTrue(collect($balancePayload['rows'])->contains(
            fn (array $row) => $row['id'] === $courier->id
                && $row['wallet_balance'] === 99_000
                && $row['budget_balance'] === 31_000
        ));
        $this->assertFalse(collect($balancePayload['rows'])->contains('id', $legacyCourier->id));
    }

    public function test_finance_card_detail_returns_at_most_one_hundred_rows_and_reports_truncation(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $courier = User::where('role', 'courier')->firstOrFail();

        for ($index = 1; $index <= 101; $index++) {
            $this->transaction($courier, 'collected', 1_000 + $index, 1, "ORD-FIN-LIMIT-{$index}");
        }

        $expectedTotal = Transaction::withoutGlobalScope(TenantScope::class)
            ->where('type', 'collected')
            ->where('direction', 1)
            ->count();

        $payload = $this->actingAs($admin)
            ->getJson('/dashboard/finance?detail=delivery_collections')
            ->assertOk()
            ->assertJsonPath('limit', 100)
            ->assertJsonPath('total', $expectedTotal)
            ->assertJsonPath('truncated', true)
            ->json();

        $this->assertCount(100, $payload['rows']);
    }

    public function test_finance_page_access_alone_cannot_read_any_finance_card_detail(): void
    {
        $profile = DashboardPermissionProfile::create([
            'name' => 'مدقق المالية للكروت',
            'permissions' => ['finance' => ['view']],
        ]);
        $operator = User::create([
            'tenant_id' => User::where('role', 'admin')->firstOrFail()->tenant_id,
            'name' => 'مدقق المالية',
            'username' => 'finance-card-viewer',
            'email' => 'finance-card-viewer@example.test',
            'phone' => '07940000122',
            'password' => 'Password123!',
            'role' => 'admin',
            'status' => 'active',
            'permission_profile_id' => $profile->id,
            'is_super_admin' => false,
        ]);

        $this->actingAs($operator)
            ->get('/dashboard/finance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Finance')
                ->where('canUpdateFinance', false)
                ->missing('summary')
                ->missing('balanceSummary')
                ->missing('requests')
                ->missing('transactions'));

        $this->actingAs($operator)
            ->getJson('/dashboard/finance?detail=delivery_collections')
            ->assertForbidden();
        $this->actingAs($operator)
            ->getJson('/dashboard/finance?detail=qi_topups')
            ->assertForbidden();
        $this->actingAs($operator)
            ->getJson('/dashboard/finance?detail=courier_balances')
            ->assertForbidden();
    }

    private function transaction(User $courier, string $type, int $amount, int $direction, string $reference, ?int $financeRequestId = null): Transaction
    {
        return Transaction::withoutGlobalScopes()->create([
            'finance_request_id' => $financeRequestId,
            'tenant_id' => $courier->tenant_id,
            'user_id' => $courier->id,
            'type' => $type,
            'amount' => $amount,
            'direction' => $direction,
            'ref' => $reference,
            'date' => today(),
            'note' => 'حركة اختبار كارت المالية',
        ]);
    }

    /** @return array{count: int, amount: int} */
    private function courierBalanceSummary(): array
    {
        $summary = User::withoutGlobalScopes()
            ->where('users.role', 'courier')
            ->where('users.status', 'active')
            ->leftJoin('wallets', 'wallets.user_id', '=', 'users.id')
            ->selectRaw('COUNT(users.id) AS card_count, COALESCE(SUM(COALESCE(wallets.balance, 0)), 0) AS card_amount')
            ->first();

        return [
            'count' => (int) ($summary->card_count ?? 0),
            'amount' => (int) ($summary->card_amount ?? 0),
        ];
    }

    /** @return array{count: int, amount: int} */
    private function transactionSummary(string $type): array
    {
        $summary = Transaction::withoutGlobalScope(TenantScope::class)
            ->where('type', $type)
            ->where('direction', 1)
            ->selectRaw('COUNT(*) AS card_count, COALESCE(SUM(amount), 0) AS card_amount')
            ->first();

        return [
            'count' => (int) ($summary->card_count ?? 0),
            'amount' => (int) ($summary->card_amount ?? 0),
        ];
    }
}
