<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Cashbox;
use App\Models\CashboxVoucher;
use App\Models\FinanceRequest;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CashboxService;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashboxCollectionScopeTest extends TestCase
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

    public function test_only_approved_courier_delivery_collections_increase_a_cashbox(): void
    {
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $admin = User::where('role', 'admin')->firstOrFail();
        $branch = $this->operatingBranch();
        $this->assignCourierToOperatingBranch($courier, $branch);

        $this->actingAs($courier)
            ->post('/app/wallet/handover', [
                'amount' => 1000,
                'branch_id' => $branch->id,
                'note' => 'تحصيل طلبات مسلّمة',
            ])
            ->assertRedirect();

        $handover = FinanceRequest::withoutGlobalScopes()
            ->where('user_id', $courier->id)
            ->where('type', FinanceRequest::CASH_HANDOVER)
            ->firstOrFail();

        $this->actingAs($admin)
            ->post("/dashboard/finance/requests/{$handover->id}/approve", [
                'approved_amount' => 1000,
                'branch_id' => $branch->id,
            ])
            ->assertRedirect();

        $cashbox = Cashbox::withoutGlobalScopes()->where('branch_id', $branch->id)->firstOrFail();
        $collectionVoucher = CashboxVoucher::withoutGlobalScopes()
            ->where('cashbox_id', $cashbox->id)
            ->where('type', 'courier_handover')
            ->firstOrFail();

        $this->assertSame(1000, (int) $cashbox->balance);
        $this->assertSame('delivered_order_collections', $collectionVoucher->meta['collection_source']);
        $this->assertSame($handover->id, $collectionVoucher->meta['finance_request_id']);
        $this->assertSame($courier->id, $collectionVoucher->meta['courier_id']);
        $this->assertSame($branch->id, $collectionVoucher->meta['branch_id']);
        $this->assertSame($handover->reference, $collectionVoucher->reference);

        // A courier's personal cash budget is available for assignments, but
        // it is never cashbox revenue.
        $this->actingAs($courier)
            ->post('/app/wallet/budget', ['amount' => 1000])
            ->assertRedirect();

        // Qi is a prepaid wallet balance and similarly has no cashbox side
        // effect, even after the administrator approves it.
        $this->actingAs($courier)
            ->post('/app/wallet/recharge', [
                'amount' => 20000,
                'qi_reference' => 'QI-CASHBOX-SCOPE-001',
            ])
            ->assertRedirect();

        $topup = FinanceRequest::withoutGlobalScopes()
            ->where('user_id', $courier->id)
            ->where('type', FinanceRequest::QI_TOPUP)
            ->firstOrFail();

        $this->actingAs($admin)
            ->post("/dashboard/finance/requests/{$topup->id}/approve", [
                'approved_amount' => 20000,
            ])
            ->assertRedirect();

        $this->assertSame(1000, (int) $cashbox->fresh()->balance);
        $this->assertSame(1, CashboxVoucher::withoutGlobalScopes()
            ->where('cashbox_id', $cashbox->id)
            ->count());
    }

    public function test_dashboard_manual_vouchers_are_rejected_without_changing_collection_cashboxes(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        app(CashboxService::class)->ensureOperatingCashboxes();
        $cashbox = Cashbox::withoutGlobalScopes()
            ->where('tenant_id', Tenant::platform()->id)
            ->where('kind', 'vault')
            ->firstOrFail();

        $this->actingAs($admin)
            ->from('/dashboard/cashboxes')
            ->post('/dashboard/cashboxes/voucher', [
                'cashbox_id' => $cashbox->id,
                'direction' => 1,
                'type' => 'receipt',
                'amount' => 50000,
                'note' => 'يجب رفض السند اليدوي',
            ])
            ->assertRedirect('/dashboard/cashboxes')
            ->assertSessionHasErrors('cashbox');

        $this->assertSame(0, (int) $cashbox->fresh()->balance);
        $this->assertSame(0, CashboxVoucher::withoutGlobalScopes()
            ->where('cashbox_id', $cashbox->id)
            ->count());
    }

    public function test_cashbox_transfer_only_moves_existing_collections_without_changing_the_total(): void
    {
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $admin = User::where('role', 'admin')->firstOrFail();
        $branch = $this->operatingBranch();
        $this->approveCollectionHandover($courier, $admin, $branch, 1000);

        app(CashboxService::class)->ensureOperatingCashboxes();
        $branchCashbox = Cashbox::withoutGlobalScopes()->where('branch_id', $branch->id)->firstOrFail();
        $vault = Cashbox::withoutGlobalScopes()
            ->where('tenant_id', Tenant::platform()->id)
            ->where('kind', 'vault')
            ->firstOrFail();
        $before = (int) Cashbox::withoutGlobalScopes()
            ->where('tenant_id', Tenant::platform()->id)
            ->sum('balance');

        $this->actingAs($admin)
            ->post('/dashboard/cashboxes/transfer', [
                'from_cashbox_id' => $branchCashbox->id,
                'to_cashbox_id' => $vault->id,
                'amount' => 600,
                'note' => 'نقل حيازة تحصيلات التوصيل',
            ])
            ->assertRedirect();

        $after = (int) Cashbox::withoutGlobalScopes()
            ->where('tenant_id', Tenant::platform()->id)
            ->sum('balance');

        $this->assertSame($before, $after);
        $this->assertSame(400, (int) $branchCashbox->fresh()->balance);
        $this->assertSame(600, (int) $vault->fresh()->balance);

        $this->assertDatabaseHas('cashbox_vouchers', [
            'cashbox_id' => $branchCashbox->id,
            'counterparty_cashbox_id' => $vault->id,
            'type' => 'transfer_out',
            'direction' => -1,
            'amount' => 600,
        ]);
        $this->assertDatabaseHas('cashbox_vouchers', [
            'cashbox_id' => $vault->id,
            'counterparty_cashbox_id' => $branchCashbox->id,
            'type' => 'transfer_in',
            'direction' => 1,
            'amount' => 600,
        ]);
    }

    public function test_historical_general_vouchers_are_excluded_and_cannot_be_transferred_as_collection_revenue(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $service = app(CashboxService::class);
        $service->ensureOperatingCashboxes();

        $vault = Cashbox::withoutGlobalScopes()
            ->where('tenant_id', Tenant::platform()->id)
            ->where('kind', 'vault')
            ->firstOrFail();
        $branchCashbox = Cashbox::withoutGlobalScopes()
            ->where('branch_id', $this->operatingBranch()->id)
            ->firstOrFail();

        // Simulate a pre-rule record. It remains stored for audit, but it is
        // not eligible collection revenue and cannot be moved forward.
        $vault->update(['balance' => 50000]);
        CashboxVoucher::withoutGlobalScopes()->create([
            'tenant_id' => Tenant::platform()->id,
            'cashbox_id' => $vault->id,
            'actor_id' => $admin->id,
            'type' => 'receipt',
            'direction' => 1,
            'amount' => 50000,
            'reference' => 'LEGACY-MANUAL-RECEIPT',
            'occurred_at' => now(),
        ]);

        $this->assertSame(0, $service->collectionBalance($vault));

        $this->actingAs($admin)
            ->from('/dashboard/cashboxes')
            ->post('/dashboard/cashboxes/transfer', [
                'from_cashbox_id' => $vault->id,
                'to_cashbox_id' => $branchCashbox->id,
                'amount' => 1000,
            ])
            ->assertRedirect('/dashboard/cashboxes')
            ->assertSessionHasErrors('amount');

        $this->assertSame(50000, (int) $vault->fresh()->balance);
        $this->assertSame(0, (int) $branchCashbox->fresh()->balance);
    }

    public function test_a_legacy_cached_debit_cannot_block_a_valid_collection_transfer(): void
    {
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $admin = User::where('role', 'admin')->firstOrFail();
        $branch = $this->operatingBranch();
        $this->approveCollectionHandover($courier, $admin, $branch, 1000);

        app(CashboxService::class)->ensureOperatingCashboxes();
        $branchCashbox = Cashbox::withoutGlobalScopes()->where('branch_id', $branch->id)->firstOrFail();
        $vault = Cashbox::withoutGlobalScopes()
            ->where('tenant_id', Tenant::platform()->id)
            ->where('kind', 'vault')
            ->firstOrFail();

        // Pre-rule manual payments could make the cached column lower than
        // the still-auditable collection vouchers. The transfer must use the
        // collection ledger, then repair the cached branch projection.
        $branchCashbox->update(['balance' => 0]);

        $this->actingAs($admin)
            ->post('/dashboard/cashboxes/transfer', [
                'from_cashbox_id' => $branchCashbox->id,
                'to_cashbox_id' => $vault->id,
                'amount' => 500,
            ])
            ->assertRedirect();

        $this->assertSame(500, (int) $branchCashbox->fresh()->balance);
        $this->assertSame(500, (int) $vault->fresh()->balance);
        $this->assertSame(500, (int) $branch->fresh()->cash_balance);
    }

    private function operatingBranch(): Branch
    {
        return Branch::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', Tenant::platform()->id)
            ->where('is_platform_managed', true)
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function approveCollectionHandover(User $courier, User $admin, Branch $branch, int $amount): void
    {
        $this->assignCourierToOperatingBranch($courier, $branch);

        $this->actingAs($courier)
            ->post('/app/wallet/handover', [
                'amount' => $amount,
                'branch_id' => $branch->id,
            ])
            ->assertRedirect();

        $handover = FinanceRequest::withoutGlobalScopes()
            ->where('user_id', $courier->id)
            ->where('type', FinanceRequest::CASH_HANDOVER)
            ->latest('id')
            ->firstOrFail();

        $this->actingAs($admin)
            ->post("/dashboard/finance/requests/{$handover->id}/approve", [
                'approved_amount' => $amount,
                'branch_id' => $branch->id,
            ])
            ->assertRedirect();
    }

    private function assignCourierToOperatingBranch(User $courier, Branch $branch): void
    {
        // A handover is now accountable to the courier's server-owned
        // operating branch. The legacy demo courier predates that field, so
        // make the fixture explicit rather than weakening the production
        // boundary for old records.
        $courier->forceFill(['branch_id' => $branch->id])->save();
    }
}
