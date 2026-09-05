<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FinanceRequest;
use App\Models\Province;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchDashboardContext;
use App\Services\CashboxService;
use App\Services\FinanceRequestService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BranchFinanceHandoverScopeTest extends TestCase
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

    public function test_courier_handover_picker_controller_and_service_are_bound_to_its_operational_branch(): void
    {
        $localBranch = $this->branch('BAG-FIN', 'بغداد', 1);
        $foreignBranch = $this->branch('BSR-FIN', 'البصرة', 2);
        $courier = $this->user('courier', 'finance-local-courier', $localBranch);

        $wallet = $this->actingAs($courier)
            ->get('/app/wallet')
            ->assertOk();

        $branchIds = collect($wallet->inertiaPage()['props']['branches'])
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->assertSame([$localBranch->id], $branchIds);

        $this->actingAs($courier)
            ->post('/app/wallet/handover', [
                'amount' => 1000,
                'branch_id' => $foreignBranch->id,
            ])
            ->assertSessionHasErrors('branch_id');

        $this->assertDatabaseMissing('finance_requests', [
            'user_id' => $courier->id,
            'type' => FinanceRequest::CASH_HANDOVER,
        ]);

        try {
            app(FinanceRequestService::class)->submit(
                $courier,
                FinanceRequest::CASH_HANDOVER,
                1000,
                $foreignBranch->id,
            );
            $this->fail('The finance service accepted a handover to another branch.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('finance', $exception->errors());
        }

        $this->assertDatabaseMissing('finance_requests', [
            'user_id' => $courier->id,
            'type' => FinanceRequest::CASH_HANDOVER,
        ]);
    }

    public function test_branch_manager_cannot_process_a_malformed_request_for_a_foreign_courier(): void
    {
        $localBranch = $this->branch('BAG-PROCESS', 'بغداد', 1);
        $foreignBranch = $this->branch('BSR-PROCESS', 'البصرة', 2);
        $manager = $this->user('branch_manager', 'finance-branch-manager', $localBranch);
        app(BranchDashboardContext::class)->assignPrimaryMembership($manager, $localBranch);
        $foreignCourier = $this->user('courier', 'finance-foreign-courier', $foreignBranch);

        // This represents a pre-hardening/corrupt row whose requested
        // cashbox is local but whose courier belongs to another branch.
        $request = FinanceRequest::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => Tenant::platform()->id,
            'user_id' => $foreignCourier->id,
            'branch_id' => $localBranch->id,
            'type' => FinanceRequest::CASH_HANDOVER,
            'amount' => 1000,
            'status' => FinanceRequest::PENDING,
            'reference' => 'FIN-FOREIGN-COURIER',
        ]);

        $financeProps = $this->actingAs($manager)
            ->get('/dashboard/finance')
            ->assertOk()
            ->inertiaPage()['props'];

        $visibleRequestIds = collect($financeProps['requests'] ?? [])
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $this->assertNotContains($request->id, $visibleRequestIds);

        $this->actingAs($manager)
            ->post('/dashboard/finance/requests/'.$request->id.'/reject')
            ->assertNotFound();

        $this->assertDatabaseHas('finance_requests', [
            'id' => $request->id,
            'status' => FinanceRequest::PENDING,
            'processed_by' => null,
        ]);
    }

    public function test_cashbox_refuses_a_courier_handover_when_the_courier_belongs_to_another_branch(): void
    {
        $localBranch = $this->branch('BAG-CASHBOX', 'بغداد', 1);
        $foreignBranch = $this->branch('BSR-CASHBOX', 'البصرة', 2);
        $foreignCourier = $this->user('courier', 'cashbox-foreign-courier', $foreignBranch);
        $request = FinanceRequest::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => Tenant::platform()->id,
            'user_id' => $foreignCourier->id,
            'branch_id' => $localBranch->id,
            'type' => FinanceRequest::CASH_HANDOVER,
            'amount' => 1000,
            'approved_amount' => 1000,
            'status' => FinanceRequest::APPROVED,
            'reference' => 'FIN-CASHBOX-FOREIGN',
        ]);

        try {
            app(CashboxService::class)->receiveCourierHandover(
                $localBranch,
                $foreignCourier,
                $request,
                1000,
                1000,
            );
            $this->fail('The cashbox accepted a courier handover from another branch.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cashbox', $exception->errors());
        }

        $this->assertDatabaseMissing('cashbox_vouchers', [
            'reference' => $request->reference,
        ]);
    }

    private function branch(string $code, string $name, int $sortOrder): Branch
    {
        $province = Province::create([
            'name_ar' => $name,
            'name_en' => $name,
            'name_ku' => $name,
            'sort_order' => $sortOrder,
            'is_active' => true,
        ]);

        return Branch::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => Tenant::platform()->id,
            'is_platform_managed' => true,
            'code' => $code,
            'name_ar' => 'فرع '.$name,
            'province_id' => $province->id,
            'is_active' => true,
        ]);
    }

    private function user(string $role, string $username, Branch $branch): User
    {
        return User::create([
            'tenant_id' => Tenant::platform()->id,
            'branch_id' => $branch->id,
            'name' => $username,
            'username' => $username,
            'email' => $username.'@example.test',
            'phone' => '079'.str_pad((string) (10000000 + User::withoutGlobalScopes()->count()), 8, '0', STR_PAD_LEFT),
            'password' => 'StrongPassword123!',
            'role' => $role,
            'status' => 'active',
            'courier_verified' => $role === 'courier',
        ]);
    }
}
