<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Cashbox;
use App\Models\CashboxVoucher;
use App\Models\FinanceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceWorkflowTest extends TestCase
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

    public function test_courier_cash_handover_needs_approval_while_declared_cash_budget_is_posted_immediately(): void
    {
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $admin = User::where('role', 'admin')->firstOrFail();
        $branch = Branch::withoutGlobalScopes()->where('is_active', true)->firstOrFail();
        $startingBranchCash = (int) $branch->cash_balance;
        $startingBudget = (int) $courier->wallet->budget;

        $this->actingAs($courier)
            ->post('/app/wallet/handover', [
                'amount' => 1000,
                'branch_id' => $branch->id,
                'note' => 'اختبار تسليم نقدي',
            ])
            ->assertRedirect();

        $handover = FinanceRequest::withoutGlobalScopes()
            ->where('user_id', $courier->id)
            ->where('type', FinanceRequest::CASH_HANDOVER)
            ->firstOrFail();

        $this->assertSame(FinanceRequest::PENDING, $handover->status);
        $this->assertDatabaseMissing('transactions', ['finance_request_id' => $handover->id]);

        $this->actingAs($admin)
            ->post("/dashboard/finance/requests/{$handover->id}/approve", [
                'approved_amount' => 1000,
                'branch_id' => $branch->id,
                'decision_note' => 'تم الاستلام في الصندوق',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('finance_requests', [
            'id' => $handover->id,
            'status' => FinanceRequest::APPROVED,
            'approved_amount' => 1000,
            'processed_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('transactions', [
            'finance_request_id' => $handover->id,
            'user_id' => $courier->id,
            'type' => FinanceRequest::CASH_HANDOVER,
            'amount' => 1000,
            'direction' => -1,
        ]);
        $this->assertSame($startingBranchCash + 1000, (int) $branch->fresh()->cash_balance);
        $cashbox = Cashbox::withoutGlobalScopes()->where('branch_id', $branch->id)->firstOrFail();
        $this->assertSame($startingBranchCash + 1000, (int) $cashbox->balance);
        $this->assertDatabaseHas('cashbox_vouchers', [
            'cashbox_id' => $cashbox->id,
            'actor_id' => $courier->id,
            'type' => 'courier_handover',
            'direction' => 1,
            'amount' => 1000,
            'reference' => $handover->reference,
        ]);
        $this->assertSame(1, CashboxVoucher::withoutGlobalScopes()
            ->where('cashbox_id', $cashbox->id)
            ->where('type', 'courier_handover')
            ->count());

        $this->actingAs($courier)
            ->post('/app/wallet/budget', ['amount' => 1000, 'note' => 'نقد متاح لاستلام الطلبات'])
            ->assertRedirect();

        $this->assertDatabaseHas('transactions', [
            'user_id' => $courier->id,
            'type' => FinanceRequest::BUDGET_RECHARGE,
            'amount' => 1000,
            'direction' => 1,
            'finance_request_id' => null,
        ]);
        $this->assertSame($startingBudget + 1000, (int) $courier->wallet->fresh()->budget);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $courier->id,
            'action' => 'wallet.courier_budget_added',
        ]);
    }

    public function test_qi_topup_needs_a_provider_reference_and_administrator_approval_before_crediting_the_courier(): void
    {
        $courier = User::where('username', 'مندوب')->firstOrFail();
        $admin = User::where('role', 'admin')->firstOrFail();
        $startingBalance = (int) $courier->wallet->balance;

        $this->actingAs($courier)
            ->post('/app/wallet/recharge', [
                'amount' => 20000,
                'qi_reference' => 'QI-TEST-20260827-01',
                'note' => 'تحويل اختبار',
            ])
            ->assertRedirect();

        $topup = FinanceRequest::withoutGlobalScopes()
            ->where('user_id', $courier->id)
            ->where('type', FinanceRequest::QI_TOPUP)
            ->firstOrFail();

        $this->assertSame(FinanceRequest::PENDING, $topup->status);
        $this->assertSame('QI-TEST-20260827-01', $topup->external_reference);
        $this->assertSame($startingBalance, (int) $courier->wallet->fresh()->balance);

        $this->actingAs($admin)
            ->post("/dashboard/finance/requests/{$topup->id}/approve", [
                'approved_amount' => 20000,
                'decision_note' => 'تمت مطابقة عملية Qi',
            ])
            ->assertRedirect();

        $this->assertSame($startingBalance + 20000, (int) $courier->wallet->fresh()->balance);
        $this->assertDatabaseHas('transactions', [
            'finance_request_id' => $topup->id,
            'type' => FinanceRequest::QI_TOPUP,
            'amount' => 20000,
            'direction' => 1,
        ]);

        $this->actingAs($courier)
            ->post('/app/wallet/recharge', [
                'amount' => 20000,
                'qi_reference' => 'QI-TEST-20260827-01',
            ])
            ->assertSessionHasErrors('finance');
    }

    public function test_merchant_payout_requires_an_administrator_approval_before_debiting_the_wallet(): void
    {
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $admin = User::where('role', 'admin')->firstOrFail();
        $startingBalance = (int) $merchant->wallet->balance;

        $this->actingAs($merchant)
            ->post('/app/wallet/withdraw', ['amount' => 2000, 'gateway' => 'cash'])
            ->assertRedirect();

        $payout = FinanceRequest::withoutGlobalScopes()
            ->where('user_id', $merchant->id)
            ->where('type', FinanceRequest::MERCHANT_PAYOUT)
            ->firstOrFail();

        $this->assertSame($startingBalance, (int) $merchant->wallet->fresh()->balance);

        $this->actingAs($admin)
            ->post("/dashboard/finance/requests/{$payout->id}/approve", ['approved_amount' => 2000])
            ->assertRedirect();

        $this->assertSame($startingBalance - 2000, (int) $merchant->wallet->fresh()->balance);
        $this->assertDatabaseHas('transactions', [
            'finance_request_id' => $payout->id,
            'type' => FinanceRequest::MERCHANT_PAYOUT,
            'direction' => -1,
            'amount' => 2000,
        ]);
    }
}
