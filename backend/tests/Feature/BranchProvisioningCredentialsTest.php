<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchMembership;
use App\Models\Order;
use App\Models\Province;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BranchProvisioningCredentialsTest extends TestCase
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

    public function test_creating_a_branch_provisions_one_scoped_manager_with_one_time_email_credentials(): void
    {
        $platform = Tenant::platform();
        $admin = $this->superAdmin($platform);
        $basra = $this->province('البصرة', 'Basra', 1);
        $baghdad = $this->province('بغداد', 'Baghdad', 2);
        $merchantTenant = Tenant::create([
            'slug' => 'credentials-merchant',
            'name' => 'تاجر اختبار الاعتمادات',
            'kind' => 'merchant',
            'status' => 'active',
        ]);
        $hiddenBranch = Branch::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $platform->id,
            'is_platform_managed' => true,
            'code' => 'BGD-HIDDEN',
            'name_ar' => 'فرع بغداد المخفي',
            'city' => 'بغداد',
            'province_id' => $baghdad->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->post('/dashboard/branches', [
            'code' => 'BAS-PORTAL',
            'name_ar' => 'فرع البصرة',
            'city' => 'البصرة',
            'province_id' => $basra->id,
        ]);

        $response
            ->assertRedirect()
            ->assertSessionHas('branch_credentials', function ($credentials) use ($basra): bool {
                return is_array($credentials)
                    && ($credentials['role'] ?? null) === 'branch_manager'
                    && ($credentials['province_id'] ?? null) === $basra->id
                    && ($credentials['province']['id'] ?? null) === $basra->id
                    && filter_var($credentials['email'] ?? null, FILTER_VALIDATE_EMAIL) !== false
                    && is_string($credentials['password'] ?? null)
                    && strlen($credentials['password']) >= 20;
            });

        /** @var array{user_id:int,branch_id:int,branch_name:string,province_id:int,province:array{id:int,name_ar:string,name_en:?string,name_ku:?string},role:string,username:string,email:string,password:string,login_url:string} $credentials */
        $credentials = app('session.store')->get('branch_credentials');
        $branch = Branch::withoutGlobalScope(TenantScope::class)
            ->where('code', 'BAS-PORTAL')
            ->firstOrFail();
        $manager = User::query()->findOrFail($credentials['user_id']);

        $this->assertSame($basra->id, (int) $branch->province_id);
        $this->assertSame('branch_manager', $manager->role);
        $this->assertSame($branch->id, (int) $manager->branch_id);
        $this->assertSame($credentials['email'], $manager->email);
        $this->assertSame($credentials['email'], $branch->email);
        $this->assertTrue(Hash::check($credentials['password'], $manager->password));
        $this->assertNotSame($credentials['password'], $manager->password);
        $this->assertSame(1, BranchMembership::query()->where('user_id', $manager->id)->count());
        $this->assertDatabaseHas('branch_memberships', [
            'branch_id' => $branch->id,
            'user_id' => $manager->id,
            'access_role' => BranchMembership::MANAGER,
            'is_primary' => true,
            'primary_user_id' => $manager->id,
        ]);

        $this->order($merchantTenant, 'CRED-VISIBLE', $branch, $basra);
        $this->order($merchantTenant, 'CRED-HIDDEN', $hiddenBranch, $baghdad);

        // Flash credentials are delivered once to the administrator and then
        // discarded rather than being serialised with the branch record.
        $this->inertia()
            ->get('/dashboard/branches')
            ->assertOk()
            ->assertJsonPath('props.flash.branch_credentials.email', $credentials['email'])
            ->assertJsonPath('props.flash.branch_credentials.password', $credentials['password']);
        $this->inertia()
            ->get('/dashboard/branches')
            ->assertOk()
            ->assertJsonPath('props.flash.branch_credentials', null)
            ->assertJsonMissing(['password' => $credentials['password']]);

        $this->post('/logout')->assertRedirect('/dashboard/login');

        // The generated email works in the existing dashboard login form.
        $this->post('/dashboard/login', [
            'username' => $credentials['email'],
            'password' => $credentials['password'],
        ])->assertRedirect('/dashboard');

        // The generated manager lands in the same dashboard URL as the
        // super administrator, but the server keeps its data within the one
        // membership-derived governorate.
        $this->inertia()
            ->get('/dashboard')
            ->assertOk()
            ->assertJsonPath('component', 'Admin/Dashboard')
            ->assertJsonPath('props.branchDashboard.active', true)
            ->assertJsonPath('props.branchDashboard.branch.id', $branch->id)
            ->assertJsonPath('props.recentOrders.0.track_no', 'CRED-VISIBLE')
            ->assertJsonMissing(['track_no' => 'CRED-HIDDEN']);

        // The full Orders module is the same route used by the platform, and
        // retains the identical server-owned branch boundary.
        $this->inertia()
            ->get('/dashboard/orders')
            ->assertOk()
            ->assertJsonPath('component', 'Admin/Orders')
            ->assertJsonPath('props.orders.data.0.track_no', 'CRED-VISIBLE')
            ->assertJsonMissing(['track_no' => 'CRED-HIDDEN']);
    }

    public function test_branch_creation_rejects_an_inactive_governorate(): void
    {
        $platform = Tenant::platform();
        $admin = $this->superAdmin($platform);
        $inactive = $this->province('محافظة معطلة', 'Disabled Governorate', 10, false);

        $this->actingAs($admin)
            ->post('/dashboard/branches', [
                'code' => 'DISABLED-PROVINCE',
                'name_ar' => 'فرع لا يجب إنشاؤه',
                'city' => 'اختبار',
                'province_id' => $inactive->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('province_id');

        $this->assertDatabaseMissing('branches', ['code' => 'DISABLED-PROVINCE']);
        $this->assertDatabaseMissing('users', ['username' => 'branch-disabled-province']);
    }

    public function test_a_paused_branch_account_cannot_create_a_dashboard_session(): void
    {
        $platform = Tenant::platform();
        $province = $this->province('بغداد', 'Baghdad', 1);
        $branch = Branch::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $platform->id,
            'is_platform_managed' => true,
            'code' => 'BGD-PAUSED-LOGIN',
            'name_ar' => 'فرع بغداد الموقوف',
            'city' => 'بغداد',
            'province_id' => $province->id,
            'is_active' => false,
        ]);
        $manager = User::create([
            'tenant_id' => $platform->id,
            'branch_id' => $branch->id,
            'name' => 'مدير فرع موقوف',
            'username' => 'paused-branch-login',
            'email' => 'paused-branch-login@example.test',
            'phone' => '07999990002',
            'password' => 'StrongPassword123!',
            'role' => 'branch_manager',
            'status' => 'active',
        ]);
        $branch->members()->attach($manager->id, ['access_role' => BranchMembership::MANAGER]);

        $this->post('/dashboard/login', [
            'username' => $manager->username,
            'password' => 'StrongPassword123!',
        ])
            ->assertRedirect()
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_reactivating_a_historical_branch_cannot_create_a_second_live_branch_for_one_province(): void
    {
        $platform = Tenant::platform();
        $admin = $this->superAdmin($platform);
        $province = $this->province('واسط', 'Wasit', 1);
        $active = Branch::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $platform->id,
            'is_platform_managed' => true,
            'code' => 'WAS-ACTIVE',
            'name_ar' => 'فرع واسط النشط',
            'province_id' => $province->id,
            'is_active' => true,
        ]);
        $historical = Branch::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $platform->id,
            'is_platform_managed' => true,
            'code' => 'WAS-HISTORY',
            'name_ar' => 'فرع واسط السابق',
            'province_id' => $province->id,
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->patch("/dashboard/branches/{$historical->id}/status", ['is_active' => true])
            ->assertRedirect()
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($active->fresh()->is_active);
        $this->assertFalse($historical->fresh()->is_active);
    }

    private function superAdmin(Tenant $platform): User
    {
        $admin = User::create([
            'tenant_id' => $platform->id,
            'name' => 'مدير اختبار',
            'username' => 'credentials-admin',
            'email' => 'credentials-admin@example.test',
            'phone' => '07999990001',
            'password' => 'StrongPassword123!',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $admin->forceFill(['is_super_admin' => true])->save();

        return $admin;
    }

    private function province(string $nameAr, string $nameEn, int $sortOrder, bool $isActive = true): Province
    {
        return Province::create([
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
        ]);
    }

    private function order(Tenant $tenant, string $trackNo, Branch $branch, Province $province): Order
    {
        return Order::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'track_no' => $trackNo,
            'source' => 'merchant',
            'customer_name_ar' => 'زبون اختبار',
            'phone' => '07800000000',
            'address_ar' => 'عنوان اختبار',
            'price' => 25000,
            'status' => 'pending',
            'origin_branch_id' => $branch->id,
            'province_id' => $province->id,
            'date' => today(),
        ]);
    }

    private function inertia(): static
    {
        return $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json')),
        ]);
    }
}
