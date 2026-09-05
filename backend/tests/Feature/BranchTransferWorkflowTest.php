<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\DashboardPermissionProfile;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchDashboardContext;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BranchTransferWorkflowTest extends TestCase
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

    public function test_admin_can_create_dispatch_and_receive_a_single_tenant_transfer(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        [$origin, $destination] = $this->networkRoute('OPS-TRF-A');
        $transporter = $this->transporter();
        $first = $this->routedOrder($merchant, $origin, $destination, 'TRF-A-001');
        $second = $this->routedOrder($merchant, $origin, $destination, 'TRF-A-002');

        $this->actingAs($admin)->get('/dashboard/transfers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Transfers')
                ->has('eligible_orders', 2)
                ->where('eligible_orders.0.origin_branch_id', $origin->id)
                ->where('eligible_orders.0.destination_branch_id', $destination->id));

        $this->actingAs($admin)->post('/dashboard/transfers', [
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination->id,
            'transporter_id' => $transporter->id,
            'order_ids' => [$first->id, $second->id],
            'notes' => 'صندوق النقل الصباحي',
        ])->assertRedirect();

        $transfer = BranchTransfer::withoutGlobalScope(TenantScope::class)->firstOrFail();
        $this->assertSame(BranchTransfer::DRAFT, $transfer->status);
        $this->assertSame($merchant->tenant_id, $transfer->tenant_id);
        $this->assertSame($origin->id, $transfer->origin_branch_id);
        $this->assertSame($destination->id, $transfer->destination_branch_id);
        $this->assertSame($transporter->id, $transfer->transporter_id);
        $this->assertSame([$first->id, $second->id], $transfer->orders()->orderBy('orders.id')->pluck('orders.id')->all());
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'branch_transfer.created',
            'subject_id' => $transfer->id,
            'tenant_id' => $merchant->tenant_id,
        ]);

        $this->actingAs($admin)->post("/dashboard/transfers/{$transfer->id}/dispatch")
            ->assertRedirect();

        $this->assertDatabaseHas('branch_transfers', [
            'id' => $transfer->id,
            'status' => BranchTransfer::DISPATCHED,
        ]);
        foreach ([$first, $second] as $order) {
            $this->assertDatabaseHas('orders', [
                'id' => $order->id,
                'workflow_stage' => 'in_transfer',
                'branch_id' => $origin->id,
            ]);
            $this->assertDatabaseHas('order_movements', [
                'order_id' => $order->id,
                'from_branch_id' => $origin->id,
                'to_branch_id' => $destination->id,
                'actor_id' => $admin->id,
                'stage' => 'in_transfer',
            ]);
            $this->assertDatabaseHas('notifications', [
                'user_id' => $merchant->id,
                'dedup_key' => "transfer:{$transfer->id}:dispatched:merchant:{$order->id}",
            ]);
        }
        $this->assertDatabaseHas('notifications', [
            'user_id' => $transporter->id,
            'dedup_key' => "transfer:{$transfer->id}:dispatched:transporter:{$transporter->id}",
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'branch_transfer.dispatched',
            'subject_id' => $transfer->id,
        ]);

        $this->actingAs($admin)->post("/dashboard/transfers/{$transfer->id}/receive")
            ->assertRedirect();

        $this->assertDatabaseHas('branch_transfers', [
            'id' => $transfer->id,
            'status' => BranchTransfer::RECEIVED,
        ]);
        foreach ([$first, $second] as $order) {
            $this->assertDatabaseHas('orders', [
                'id' => $order->id,
                'workflow_stage' => 'at_destination_branch',
                'branch_id' => $destination->id,
            ]);
            $this->assertDatabaseHas('order_movements', [
                'order_id' => $order->id,
                'from_branch_id' => $origin->id,
                'to_branch_id' => $destination->id,
                'actor_id' => $admin->id,
                'stage' => 'at_destination_branch',
            ]);
            $this->assertDatabaseHas('notifications', [
                'user_id' => $merchant->id,
                'dedup_key' => "transfer:{$transfer->id}:received:merchant:{$order->id}",
            ]);
        }
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'branch_transfer.received',
            'subject_id' => $transfer->id,
        ]);
    }

    public function test_transfer_operations_do_not_expose_cod_values_without_the_finance_balance_permission(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        [$origin, $destination] = $this->networkRoute('OPS-TRF-FINANCE');
        $transporter = $this->transporter();
        $transferOrder = $this->routedOrder($merchant, $origin, $destination, 'TRF-FIN-001');
        $transferOrder->update(['price' => 83_500]);
        $eligibleOrder = $this->routedOrder($merchant, $origin, $destination, 'TRF-FIN-002');
        $eligibleOrder->update(['price' => 61_750]);

        $this->actingAs($admin)->post('/dashboard/transfers', [
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination->id,
            'transporter_id' => $transporter->id,
            'order_ids' => [$transferOrder->id],
        ])->assertRedirect();

        $transfer = BranchTransfer::withoutGlobalScope(TenantScope::class)->firstOrFail();
        $operator = $this->transferOperator('transfer-operations-only', '07999110101', [
            'transfers' => ['view', 'create', 'dispatch'],
        ]);

        $operatorProps = $this->actingAs($operator)
            ->get('/dashboard/transfers')
            ->assertOk()
            ->inertiaPage()['props'];

        $this->assertFalse($operatorProps['canViewTransferFinancials']);
        $operatorTransferOrder = $operatorProps['transfers']['data'][0]['orders'][0];
        $operatorEligibleOrder = collect($operatorProps['eligible_orders'])->firstWhere('id', $eligibleOrder->id);
        $this->assertArrayNotHasKey('price', $operatorTransferOrder);
        $this->assertIsArray($operatorEligibleOrder);
        $this->assertArrayNotHasKey('price', $operatorEligibleOrder);

        // The same operator can still perform their assigned logistics task.
        $this->actingAs($operator)->post("/dashboard/transfers/{$transfer->id}/dispatch")
            ->assertRedirect();
        $this->assertDatabaseHas('branch_transfers', [
            'id' => $transfer->id,
            'status' => BranchTransfer::DISPATCHED,
        ]);

        $financialOperator = $this->transferOperator('transfer-finance-viewer', '07999110102', [
            'transfers' => ['view', 'create'],
            'finance' => ['view', 'view_balances'],
        ]);
        $financialProps = $this->actingAs($financialOperator)
            ->get('/dashboard/transfers')
            ->assertOk()
            ->inertiaPage()['props'];

        $this->assertTrue($financialProps['canViewTransferFinancials']);
        $financialTransferOrder = $financialProps['transfers']['data'][0]['orders'][0];
        $financialEligibleOrder = collect($financialProps['eligible_orders'])->firstWhere('id', $eligibleOrder->id);
        $this->assertSame(83_500, $financialTransferOrder['price']);
        $this->assertIsArray($financialEligibleOrder);
        $this->assertSame(61_750, $financialEligibleOrder['price']);
    }

    public function test_transfer_rejects_mixed_tenant_orders_and_private_foreign_branch_routes(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        [$origin, $destination] = $this->networkRoute('OPS-TRF-B');
        $transporter = $this->transporter();
        $merchantOrder = $this->routedOrder($merchant, $origin, $destination, 'TRF-B-001');

        $otherTenant = Tenant::create([
            'slug' => 'transfer-other-merchant',
            'name' => 'تاجر آخر',
            'kind' => 'merchant',
            'status' => 'active',
        ]);
        $otherMerchant = User::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'تاجر مختلف',
            'username' => 'transfer-other-merchant',
            'phone' => '07999110001',
            'password' => 'password',
            'role' => 'merchant',
            'status' => 'active',
        ]);
        $otherOrder = $this->routedOrder($otherMerchant, $origin, $destination, 'TRF-B-002');

        // A single manifest cannot cross merchant tenant boundaries even if
        // both orders use the public operations network branches.
        $this->actingAs($admin)->post('/dashboard/transfers', [
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination->id,
            'transporter_id' => $transporter->id,
            'order_ids' => [$merchantOrder->id, $otherOrder->id],
        ])->assertRedirect()->assertSessionHasErrors('transfer');

        $this->assertDatabaseCount('branch_transfers', 0);

        $foreignPrivateBranch = Branch::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $otherTenant->id,
            'code' => 'PRIVATE-OTHER',
            'name_ar' => 'فرع خاص لتاجر آخر',
            'city' => 'البصرة',
            'is_active' => true,
        ]);

        // The admin cannot smuggle a merchant's order through another
        // merchant's private branch. `canServeTenant` is enforced on write.
        $this->actingAs($admin)->post('/dashboard/transfers', [
            'origin_branch_id' => $foreignPrivateBranch->id,
            'destination_branch_id' => $destination->id,
            'transporter_id' => $transporter->id,
            'order_ids' => [$merchantOrder->id],
        ])->assertRedirect()->assertSessionHasErrors('transfer');

        $this->assertDatabaseCount('branch_transfers', 0);
        $this->assertDatabaseMissing('order_movements', ['order_id' => $merchantOrder->id, 'stage' => 'in_transfer']);
    }

    public function test_transfer_cannot_be_received_before_dispatch_or_reused_while_active(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        [$origin, $destination] = $this->networkRoute('OPS-TRF-C');
        $transporter = $this->transporter();
        $order = $this->routedOrder($merchant, $origin, $destination, 'TRF-C-001');

        $this->actingAs($admin)->post('/dashboard/transfers', [
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination->id,
            'transporter_id' => $transporter->id,
            'order_ids' => [$order->id],
            'notes' => 'remote-note-sentinel',
        ])->assertRedirect();

        $transfer = BranchTransfer::withoutGlobalScope(TenantScope::class)->firstOrFail();

        $this->actingAs($admin)->post("/dashboard/transfers/{$transfer->id}/receive")
            ->assertRedirect()
            ->assertSessionHasErrors('transfer');

        $this->actingAs($admin)->post('/dashboard/transfers', [
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination->id,
            'transporter_id' => $transporter->id,
            'order_ids' => [$order->id],
        ])->assertRedirect()->assertSessionHasErrors('transfer');

        $this->assertDatabaseCount('branch_transfers', 1);
        $this->assertDatabaseHas('branch_transfers', ['id' => $transfer->id, 'status' => BranchTransfer::DRAFT]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'workflow_stage' => 'awaiting_transfer']);
    }

    public function test_branch_manager_receives_only_the_minimum_incoming_transfer_manifest(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        [$origin, $destination] = $this->networkRoute('OPS-TRF-PRIVATE');
        $merchant->update([
            'name' => 'remote-merchant-sentinel',
            'shop_name' => 'remote-shop-sentinel',
            'phone' => '07999119990',
        ]);
        Tenant::query()->whereKey($merchant->tenant_id)->update(['name' => 'remote-tenant-sentinel']);

        $transporter = $this->transporter();
        $transporter->update([
            'name' => 'remote-transporter-sentinel',
            'phone' => '07999119991',
        ]);
        $order = $this->routedOrder($merchant, $origin, $destination, 'TRF-PRIVATE-001');

        $this->actingAs($admin)->post('/dashboard/transfers', [
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination->id,
            'transporter_id' => $transporter->id,
            'order_ids' => [$order->id],
        ])->assertRedirect();

        $transfer = BranchTransfer::withoutGlobalScope(TenantScope::class)->firstOrFail();
        $this->actingAs($admin)->post("/dashboard/transfers/{$transfer->id}/dispatch")
            ->assertRedirect();

        $manager = $this->branchManager($destination, 'incoming-transfer-manager', '07999119992');
        $response = $this->actingAs($manager)
            ->get('/dashboard/transfers')
            ->assertOk()
            ->assertDontSee('remote-merchant-sentinel')
            ->assertDontSee('remote-shop-sentinel')
            ->assertDontSee('remote-tenant-sentinel')
            ->assertDontSee('07999119990')
            ->assertDontSee('07999119991')
            ->assertDontSee('remote-note-sentinel');

        $props = $response->inertiaPage()['props'];
        $incoming = collect($props['transfers']['data'])->firstWhere('id', $transfer->id);

        $this->assertIsArray($incoming);
        $this->assertArrayNotHasKey('notes', $incoming);
        $this->assertSame(['id', 'name_ar'], array_keys($incoming['origin_branch']));
        $this->assertSame($origin->id, $incoming['origin_branch']['id']);
        $this->assertSame(['id', 'name'], array_keys($incoming['transporter']));
        $this->assertSame($transporter->id, $incoming['transporter']['id']);
        $pickerTransporter = collect($props['transporters'])->firstWhere('id', $transporter->id);
        $this->assertIsArray($pickerTransporter);
        $this->assertSame(['id', 'name'], array_keys($pickerTransporter));

        $incomingOrder = $incoming['orders'][0];
        $this->assertSame(['id', 'track_no', 'status', 'workflow_stage'], array_keys($incomingOrder));
        $this->assertSame('TRF-PRIVATE-001', $incomingOrder['track_no']);
        $this->assertArrayNotHasKey('customer', $incomingOrder);
        $this->assertArrayNotHasKey('merchant', $incomingOrder);
        $this->assertArrayNotHasKey('tenant', $incomingOrder);
        $this->assertArrayNotHasKey('price', $incomingOrder);
        $this->assertNotContains($order->id, collect($props['eligible_orders'])->pluck('id')->all());

        // A visible incoming transfer can be received, but its origin cannot
        // be used to dispatch or create an outbound transfer from this branch.
        $this->actingAs($manager)->post("/dashboard/transfers/{$transfer->id}/dispatch")
            ->assertNotFound();
        $this->actingAs($manager)->post("/dashboard/transfers/{$transfer->id}/receive")
            ->assertRedirect();
        $this->assertDatabaseHas('branch_transfers', [
            'id' => $transfer->id,
            'status' => BranchTransfer::RECEIVED,
        ]);
    }

    /** @return array{Branch, Branch} */
    private function networkRoute(string $prefix): array
    {
        $platform = Tenant::platform();

        $origin = Branch::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $platform->id,
            'is_platform_managed' => true,
            'code' => $prefix.'-ORIGIN',
            'name_ar' => 'فرع استلام '.$prefix,
            'name_en' => 'Origin '.$prefix,
            'city' => 'بغداد',
            'is_active' => true,
        ]);
        $destination = Branch::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $platform->id,
            'is_platform_managed' => true,
            'code' => $prefix.'-DEST',
            'name_ar' => 'فرع وجهة '.$prefix,
            'name_en' => 'Destination '.$prefix,
            'city' => 'البصرة',
            'is_active' => true,
        ]);

        return [$origin, $destination];
    }

    private function transporter(): User
    {
        return User::create([
            'tenant_id' => Tenant::platform()->id,
            'name' => 'ناقل التحويلات',
            'username' => 'transfer-transporter',
            'phone' => '07999110002',
            'password' => 'password',
            'role' => 'transporter',
            'status' => 'active',
        ]);
    }

    private function branchManager(Branch $branch, string $username, string $phone): User
    {
        $manager = User::create([
            'tenant_id' => Tenant::platform()->id,
            'branch_id' => $branch->id,
            'name' => $username,
            'username' => $username,
            'email' => $username.'@example.test',
            'phone' => $phone,
            'password' => 'Password123!',
            'role' => 'branch_manager',
            'status' => 'active',
            'is_super_admin' => false,
        ]);

        app(BranchDashboardContext::class)->assignPrimaryMembership($manager, $branch);

        return $manager->fresh();
    }

    /** @param array<string, array<int, string>> $permissions */
    private function transferOperator(string $username, string $phone, array $permissions): User
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

    private function routedOrder(User $merchant, Branch $origin, Branch $destination, string $trackNo): Order
    {
        return Order::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => $trackNo,
            'source' => 'merchant',
            'customer_name_ar' => 'عميل التحويل',
            'customer_name_en' => 'Transfer customer',
            'phone' => '07710009999',
            'address_ar' => 'بغداد',
            'address_en' => 'Baghdad',
            'delivery_vehicle' => 'normal',
            'price' => 25000,
            'fee' => 3000,
            'status' => 'approved',
            'workflow_stage' => 'awaiting_transfer',
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination->id,
            'branch_id' => $origin->id,
            'province_id' => $merchant->provinces()->value('provinces.id'),
            'date' => today(),
        ]);
    }
}
