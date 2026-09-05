<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Province;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CourierOrderAccess;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminStatusAndOperationalRolesTest extends TestCase
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

    public function test_admin_orders_support_cancelled_damaged_rejected_and_safe_late_filters(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();

        $cancelled = $this->makeOrder($merchant, $province, 'ALM-CANCELLED-STATUS', 'cancelled');
        $damaged = $this->makeOrder($merchant, $province, 'ALM-DAMAGED-STATUS', 'damaged');
        $rejected = $this->makeOrder($merchant, $province, 'ALM-REJECTED-STATUS', 'rejected');
        $late = $this->makeOrder($merchant, $province, 'ALM-LATE-STATUS', 'pending', now()->subMinutes(10));

        $this->actingAs($admin)->get('/dashboard/orders?filter=cancelled')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Orders')
                ->where('orders.data.0.id', $cancelled->id)
                ->where('orders.data.0.status', 'cancelled')
                ->where('counts.cancelled', 1)
                ->where('counts.damaged', 1)
                ->where('counts.rejected', 1)
                ->where('counts.late', 1));

        $this->actingAs($admin)->get('/dashboard/orders?filter=damaged')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Orders')
                ->where('orders.data.0.id', $damaged->id)
                ->where('orders.data.0.status', 'damaged'));

        $this->actingAs($admin)->get('/dashboard/orders?filter=rejected')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Orders')
                ->where('orders.data.0.id', $rejected->id)
                ->where('orders.data.0.status', 'rejected'));

        $this->actingAs($admin)->get('/dashboard/orders?filter=late')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Orders')
                ->where('orders.data.0.id', $late->id)
                ->where('orders.data.0.status', 'pending'));

        // A late order remains pending. It is a deadline exception, so an
        // administrator cannot accidentally mutate it into a fake status.
        $this->actingAs($admin)->post("/dashboard/orders/{$late->id}/status", ['status' => 'late'])
            ->assertRedirect()
            ->assertSessionHasErrors('status');
        $this->assertDatabaseHas('orders', ['id' => $late->id, 'status' => 'pending']);

        $this->actingAs($admin)->post("/dashboard/orders/{$late->id}/status", ['status' => 'cancelled'])
            ->assertRedirect();

        $this->assertDatabaseHas('orders', [
            'id' => $late->id,
            'status' => 'cancelled',
            'workflow_stage' => 'cancelled',
        ]);
        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $late->id,
            'from_status' => 'pending',
            'to_status' => 'cancelled',
            'user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('order_movements', [
            'order_id' => $late->id,
            'stage' => 'cancelled',
        ]);
    }

    public function test_dashboard_order_detail_includes_the_return_reason(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $order = $this->makeOrder($merchant, $province, 'ALM-ADMIN-RETURN-REASON', 'returned');
        $order->update([
            'return_fee_mode' => 'none',
            'return_reason' => 'العنوان غير صحيح ولم يجب العميل على الاتصال.',
        ]);

        $this->actingAs($admin)
            ->getJson("/dashboard/orders?detail={$order->id}")
            ->assertOk()
            ->assertJsonPath('order.id', $order->id)
            ->assertJsonPath('order.return_fee_mode', 'none')
            ->assertJsonPath('order.return_reason', 'العنوان غير صحيح ولم يجب العميل على الاتصال.');
    }

    public function test_specialist_accounts_remain_historical_but_are_excluded_from_direct_courier_directories(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $courierTenant = User::where('username', 'مندوب')->firstOrFail()->tenant;

        $pickup = $this->makeOperationalUser($courierTenant, $province, 'pickup_courier', 'pickup-ops', '07920000011');
        $delivery = $this->makeOperationalUser($courierTenant, $province, 'delivery_courier', 'delivery-ops', '07920000012');
        $transporter = $this->makeOperationalUser($courierTenant, $province, 'transporter', 'transporter-ops', '07920000013');
        $courier = $this->makeOperationalUser($courierTenant, $province, 'courier', 'single-courier-ops', '07920000014');
        Wallet::updateOrCreate(
            ['user_id' => $courier->id],
            ['balance' => 100000, 'budget' => 100000, 'budget_balance' => 100000],
        );
        $order = $this->makeOrder($merchant, $province, 'ALM-ROLE-ASSIGNMENT', 'pending');

        $roster = $this->actingAs($admin)->get('/dashboard/couriers?role=courier')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Roster')
                ->where('role', 'courier'));

        $rosterRows = collect($roster->inertiaProps('rows'));
        $this->assertTrue($rosterRows->contains(fn (array $row) => data_get($row, 'user.id') === $courier->id));
        $this->assertFalse($rosterRows->contains(fn (array $row) => data_get($row, 'user.id') === $pickup->id));
        $this->assertFalse($rosterRows->contains(fn (array $row) => data_get($row, 'user.id') === $delivery->id));
        $this->assertFalse($rosterRows->contains(fn (array $row) => data_get($row, 'user.id') === $transporter->id));

        Sanctum::actingAs($admin);
        $directory = $this->getJson('/api/v1/admin/couriers')
            ->assertOk()
            ->assertJsonFragment(['id' => $courier->id, 'role' => 'courier']);
        $directory
            ->assertJsonMissing(['id' => $pickup->id])
            ->assertJsonMissing(['id' => $delivery->id])
            ->assertJsonMissing(['id' => $transporter->id]);
        $this->getJson('/api/v1/admin/couriers?role=delivery_courier')
            ->assertOk()
            ->assertJsonFragment(['id' => $courier->id, 'role' => 'courier'])
            ->assertJsonMissing(['id' => $delivery->id]);

        // Legacy accounts remain individually discoverable through the full
        // administrative user directory, rather than disappearing from audit
        // and branch-transfer history.
        $this->getJson('/api/v1/admin/users?role=transporter')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $transporter->id,
                'role' => 'transporter',
            ]);

        $this->actingAs($admin)->post("/dashboard/orders/{$order->id}/courier", [
            'courier_id' => $pickup->id,
            'assignment_role' => 'pickup_courier',
        ])->assertRedirect()
            ->assertSessionHasErrors('assignment_role');

        $this->actingAs($admin)->post("/dashboard/orders/{$order->id}/courier", [
            'courier_id' => $pickup->id,
            'assignment_role' => 'courier',
        ])->assertRedirect()
            ->assertSessionHasErrors('courier_id');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending',
            'courier_id' => null,
            'pickup_courier_id' => null,
            'delivery_courier_id' => null,
        ]);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/admin/orders/{$order->id}/courier", [
            'courier_id' => $delivery->id,
            'assignment_role' => 'delivery_courier',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['courier_id', 'assignment_role']);

        $assignment = $this->patchJson("/api/v1/admin/orders/{$order->id}/courier", [
            'courier_id' => $courier->id,
            'assignment_role' => 'courier',
        ])->assertOk()
            ->assertJsonPath('data.courier_id', $courier->id)
            ->assertJsonPath('data.status', 'approved');

        $assignment
            ->assertJsonMissingPath('data.pickup_courier_id')
            ->assertJsonMissingPath('data.delivery_courier_id');

        $this->assertTrue(app(CourierOrderAccess::class)->assigned($courier)->whereKey($order->id)->exists());
        $this->assertFalse(app(CourierOrderAccess::class)->assigned($pickup)->whereKey($order->id)->exists());

        $this->actingAs($pickup)->get('/app/orders?filter=pending')
            ->assertForbidden();
        $this->actingAs($pickup)->get('/app')
            ->assertForbidden();
        $this->actingAs($pickup)->get('/app/reports')
            ->assertForbidden();
        Sanctum::actingAs($pickup);
        $this->getJson('/api/v1/orders')
            ->assertForbidden();
        $this->getJson('/api/v1/dashboard')
            ->assertForbidden();

        // Transporters are available in operations and campaigns, but their
        // direct order assignment is blocked so they can only be used by the
        // dedicated inter-branch transfer workflow.
        $unassignedOrder = $this->makeOrder($merchant, $province, 'ALM-TRANSPORTER-ASSIGNMENT', 'pending');
        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/admin/orders/{$unassignedOrder->id}/courier", [
            'courier_id' => $transporter->id,
            'assignment_role' => 'courier',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('courier_id');

        $this->actingAs($admin)->post('/dashboard/notifications', [
            'audience' => 'pickup_couriers',
            'type' => 'announcement',
            'title_ar' => 'مهمة استلام',
            'body_ar' => 'يرجى مراجعة قائمة الاستلام.',
        ])->assertRedirect();
        $this->assertDatabaseHas('notifications', [
            'user_id' => $pickup->id,
            'title_ar' => 'مهمة استلام',
        ]);

        $this->actingAs($admin)->post('/dashboard/notifications', [
            'audience' => 'couriers',
            'type' => 'announcement',
            'title_ar' => 'تعميم العمليات',
            'body_ar' => 'رسالة إلى جميع فرق التوصيل والنقل.',
        ])->assertRedirect();
        $this->assertDatabaseHas('notifications', [
            'user_id' => $transporter->id,
            'title_ar' => 'تعميم العمليات',
        ]);
    }

    private function makeOrder(User $merchant, Province $province, string $track, string $status, $deadline = null): Order
    {
        return Order::withoutGlobalScopes()->create([
            'tenant_id' => $merchant->tenant_id,
            'merchant_id' => $merchant->id,
            'created_by' => $merchant->id,
            'track_no' => $track,
            'source' => 'merchant',
            'customer_name_ar' => 'عميل اختبار',
            'customer_name_en' => 'Test customer',
            'phone' => '078'.str_pad((string) random_int(1, 9_999_999), 7, '0', STR_PAD_LEFT),
            'address_ar' => 'بغداد — الكرادة',
            'address_en' => 'Baghdad — Karrada',
            'delivery_vehicle' => 'normal',
            'price' => 25000,
            'fee' => 2500,
            'status' => $status,
            'workflow_stage' => $status === 'rejected' ? 'rejected' : $status,
            'province_id' => $province->id,
            'date' => today(),
            'pickup_deadline_at' => $deadline,
        ]);
    }

    private function makeOperationalUser(Tenant $tenant, Province $province, string $role, string $username, string $phone): User
    {
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $username,
            'username' => $username,
            'phone' => $phone,
            'password' => 'password',
            'role' => $role,
            'status' => 'active',
            'vehicle' => 'bike',
        ]);
        $user->provinces()->syncWithoutDetaching([$province->id => ['is_primary' => true]]);

        return $user;
    }
}
