<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Province;
use App\Models\Tenant;
use App\Models\User;
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

    public function test_specialised_courier_roles_are_filterable_assignable_and_notification_targetable(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('username', 'تاجر')->firstOrFail();
        $province = $merchant->provinces()->firstOrFail();
        $courierTenant = User::where('username', 'مندوب')->firstOrFail()->tenant;

        $pickup = $this->makeOperationalUser($courierTenant, $province, 'pickup_courier', 'pickup-ops', '07920000011');
        $delivery = $this->makeOperationalUser($courierTenant, $province, 'delivery_courier', 'delivery-ops', '07920000012');
        $transporter = $this->makeOperationalUser($courierTenant, $province, 'transporter', 'transporter-ops', '07920000013');
        $order = $this->makeOrder($merchant, $province, 'ALM-ROLE-ASSIGNMENT', 'pending');

        $this->actingAs($admin)->get('/dashboard/couriers?role=pickup_courier')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Roster')
                ->where('selectedRole', 'pickup_courier')
                ->where('roleFilters.pickup_courier', 1)
                ->where('rows.0.user.id', $pickup->id)
                ->where('rows.0.user.role', 'pickup_courier'));

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/couriers?role=delivery_courier')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $delivery->id,
                'role' => 'delivery_courier',
            ]);
        $this->getJson('/api/v1/admin/users?role=transporter')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $transporter->id,
                'role' => 'transporter',
            ]);

        $this->actingAs($admin)->post("/dashboard/orders/{$order->id}/courier", [
            'courier_id' => $pickup->id,
            'assignment_role' => 'pickup_courier',
        ])->assertRedirect();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'pickup_courier_id' => $pickup->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('order_movements', [
            'order_id' => $order->id,
            'stage' => 'pickup_assigned',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $pickup->id,
            'type' => 'order',
        ]);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/admin/orders/{$order->id}/courier", [
            'courier_id' => $delivery->id,
            'assignment_role' => 'delivery_courier',
        ])->assertOk()
            ->assertJsonPath('data.pickup_courier_id', $pickup->id)
            ->assertJsonPath('data.delivery_courier_id', $delivery->id)
            ->assertJsonPath('data.status', 'pending');

        // Transporters are available in operations and campaigns, but their
        // direct order assignment is blocked so they can only be used by the
        // dedicated inter-branch transfer workflow.
        $this->patchJson("/api/v1/admin/orders/{$order->id}/courier", [
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
