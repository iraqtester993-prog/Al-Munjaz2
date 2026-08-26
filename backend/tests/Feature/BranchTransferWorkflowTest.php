<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
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
