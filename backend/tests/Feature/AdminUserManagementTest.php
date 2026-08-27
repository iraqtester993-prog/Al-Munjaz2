<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
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

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_merchant_roster_lists_each_actual_merchant_account_and_its_review_data(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('role', 'merchant')->firstOrFail();
        $secondMerchant = User::create([
            'tenant_id' => $merchant->tenant_id,
            'name' => 'تاجر إضافي',
            'username' => 'merchant-second',
            'phone' => '07919990111',
            'password' => 'Password123!',
            'role' => 'merchant',
            'status' => 'active',
            'shop_name' => 'متجر ثانٍ',
            'address' => 'بغداد — المنصور',
        ]);

        $response = $this->actingAs($admin)
            ->get('/dashboard/merchants')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Roster')
                ->where('role', 'merchant')
                ->has('rows', 2));

        $rows = $response->inertiaProps('rows');

        $this->assertTrue(collect($rows)->contains(fn (array $row) => (
            data_get($row, 'user.id') === $secondMerchant->id
            && data_get($row, 'user.shop_name') === 'متجر ثانٍ'
            && data_get($row, 'user.address') === 'بغداد — المنصور'
            && data_get($row, 'user.username') === 'merchant-second'
        )));
    }

    public function test_admin_can_correct_operational_account_data_without_changing_its_role(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $courier = User::where('role', 'courier')->firstOrFail();

        $this->actingAs($admin)
            ->put('/dashboard/users/'.$courier->id, [
                'name' => 'مندوب محدّث',
                'username' => 'courier-updated',
                'email' => 'courier.updated@example.test',
                'phone' => '07919990112',
                'shop_name' => 'must-not-be-used',
                'address' => 'بغداد — الكرادة',
                'vehicle' => 'sedan',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $courier->id,
            'name' => 'مندوب محدّث',
            'username' => 'courier-updated',
            'email' => 'courier.updated@example.test',
            'phone' => '07919990112',
            'address' => 'بغداد — الكرادة',
            'vehicle' => 'sedan',
            'role' => 'courier',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'user.profile_updated_by_admin',
            'subject_type' => User::class,
            'subject_id' => $courier->id,
        ]);
        $this->assertSame('courier', $courier->fresh()->role);
        $this->assertNull($courier->fresh()->shop_name);
        $this->assertSame(1, ActivityLog::query()->where('action', 'user.profile_updated_by_admin')->count());
    }

    public function test_suspending_one_operational_account_does_not_suspend_its_whole_company(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $merchant = User::where('role', 'merchant')->firstOrFail();
        $merchant->tenant->update(['status' => 'active']);

        $this->actingAs($admin)
            ->post('/dashboard/users/'.$merchant->id.'/status', ['status' => 'suspended'])
            ->assertRedirect();

        $this->assertSame('suspended', $merchant->fresh()->status);
        $this->assertSame('active', $merchant->tenant->fresh()->status);
    }
}
