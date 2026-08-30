<?php

namespace Tests\Feature;

use App\Models\DashboardInvitation;
use App\Models\DashboardPermissionProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminEmployeeManagementTest extends TestCase
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

    public function test_super_administrator_sees_only_administrative_staff_with_profiles_permissions_and_invitation_state(): void
    {
        $superAdmin = $this->superAdministrator();
        $profile = $this->profile('مشغل الطلبات', ['orders' => ['view', 'update']]);
        $employee = $this->employee('staff-roster', $profile);

        DashboardInvitation::create([
            'invited_by' => $superAdmin->id,
            'name' => 'موظف بانتظار الدعوة',
            'email' => 'pending-employee@example.test',
            'role' => 'admin',
            'permission_profile_id' => $profile->id,
            'token_hash' => hash('sha256', 'pending-employee-token'),
            'expires_at' => now()->addWeek(),
        ]);

        $response = $this->actingAs($superAdmin)
            ->get(route('admin.employees'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Employees')
                ->has('employees', 2)
                ->has('profiles', 1)
                ->has('permissionModules')
                ->has('invitations', 1)
                ->where('employees.1.id', $employee->id)
                ->where('employees.1.permission_profile.id', $profile->id)
                ->where('employees.1.effective_permissions.orders', ['view', 'update'])
                ->where('profiles.0.employees_count', 1)
                ->where('invitations.0.state', 'pending')
            );

        $employees = $response->inertiaPage()['props']['employees'];
        $this->assertSame(['admin'], collect($employees)->pluck('role')->unique()->values()->all());
        $this->assertSame(['orders' => ['view', 'update']], $employees[1]['permission_profile']['permissions']);
        $this->assertDatabaseMissing('dashboard_invitations', [
            'email' => 'pending-employee@example.test',
            'permission_profile_id' => null,
        ]);
    }

    public function test_only_a_super_administrator_can_read_or_mutate_the_staff_directory(): void
    {
        $superAdmin = $this->superAdministrator();
        $profile = $this->profile('مشغل محدود', ['platform' => ['view']]);
        $employee = $this->employee('limited-operator', $profile);

        $this->actingAs($employee)
            ->get(route('admin.employees'))
            ->assertForbidden();
        $this->actingAs($employee)
            ->post(route('admin.employees.invitations.store'), [
                'name' => 'محاولة تصعيد',
                'email' => 'forbidden-invite@example.test',
                'permission_profile_id' => $profile->id,
            ])
            ->assertForbidden();
        $this->actingAs($employee)
            ->put(route('admin.employees.update', $superAdmin), [
                'name' => 'محاولة تصعيد',
                'username' => $superAdmin->username,
                'email' => $superAdmin->email,
                'phone' => $superAdmin->phone,
                'permission_profile_id' => $profile->id,
                'is_super_admin' => true,
            ])
            ->assertForbidden();
        $this->actingAs($employee)
            ->patch(route('admin.employees.status', $superAdmin), ['status' => 'suspended'])
            ->assertForbidden();
        $this->actingAs($employee)
            ->delete(route('admin.employees.destroy', $superAdmin))
            ->assertForbidden();

        $this->assertDatabaseMissing('dashboard_invitations', ['email' => 'forbidden-invite@example.test']);
        $this->assertTrue($superAdmin->fresh()->isSuperAdmin());
    }

    public function test_super_administrator_can_invite_a_scoped_employee_without_minting_super_admin_access(): void
    {
        $superAdmin = $this->superAdministrator();
        $profile = $this->profile('مشغل المالية', ['finance' => ['view']]);

        $response = $this->actingAs($superAdmin)
            ->post(route('admin.employees.invitations.store'), [
                'name' => 'موظف مالية',
                'email' => 'finance-employee@example.test',
                'permission_profile_id' => $profile->id,
                'expires_in_days' => 10,
                // Extra fields are intentionally ignored by the server.
                'role' => 'merchant',
                'is_super_admin' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('invite_link');

        $invitation = DashboardInvitation::query()
            ->where('email', 'finance-employee@example.test')
            ->firstOrFail();
        $this->assertSame('admin', $invitation->role);
        $this->assertSame($profile->id, $invitation->permission_profile_id);
        $this->assertTrue($invitation->expires_at->between(
            now()->addDays(9),
            now()->addDays(10)->addMinute(),
        ));
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $superAdmin->id,
            'action' => 'dashboard.employee.invited',
            'subject_type' => DashboardInvitation::class,
            'subject_id' => $invitation->id,
        ]);

        $invitePath = parse_url((string) $response->getSession()->get('invite_link'), PHP_URL_PATH);
        $this->post(route('logout'))->assertRedirect();

        $this->post($invitePath, [
            'name' => 'موظف مالية',
            'username' => 'finance-employee',
            'phone' => '07980000111',
            'password' => 'StrongPassword123',
            'password_confirmation' => 'StrongPassword123',
        ])->assertRedirect('/dashboard/finance');

        $employee = User::query()->where('username', 'finance-employee')->firstOrFail();
        $this->assertSame('admin', $employee->role);
        $this->assertSame('active', $employee->status);
        $this->assertFalse($employee->isSuperAdmin());
        $this->assertSame($profile->id, $employee->permission_profile_id);
        $this->assertSame(Tenant::PLATFORM_SLUG, $employee->tenant->slug);
    }

    public function test_staff_invitation_requires_an_explicit_existing_permission_profile(): void
    {
        $superAdmin = $this->superAdministrator();

        $this->actingAs($superAdmin)
            ->post(route('admin.employees.invitations.store'), [
                'name' => 'موظف بلا صلاحية',
                'email' => 'no-profile@example.test',
            ])
            ->assertSessionHasErrors('permission_profile_id');

        $this->actingAs($superAdmin)
            ->post(route('admin.employees.invitations.store'), [
                'name' => 'موظف بملف غير موجود',
                'email' => 'missing-profile@example.test',
                'permission_profile_id' => 999999,
            ])
            ->assertSessionHasErrors('permission_profile_id');

        $this->assertDatabaseMissing('dashboard_invitations', ['email' => 'no-profile@example.test']);
        $this->assertDatabaseMissing('dashboard_invitations', ['email' => 'missing-profile@example.test']);
    }

    public function test_super_administrator_can_update_basic_staff_fields_and_profile_but_never_promote_a_user(): void
    {
        $superAdmin = $this->superAdministrator();
        $oldProfile = $this->profile('مشغل الطلبات', ['orders' => ['view']]);
        $newProfile = $this->profile('مشغل المحتوى', ['content' => ['view', 'update']]);
        $employee = $this->employee('editable-staff', $oldProfile);

        $this->actingAs($superAdmin)
            ->put(route('admin.employees.update', $employee), [
                'name' => 'موظف تم تعديله',
                'username' => 'edited-staff',
                'email' => 'edited-staff@example.test',
                'phone' => '07980000222',
                'permission_profile_id' => $newProfile->id,
                // These inputs have no mutation path in the controller.
                'role' => 'merchant',
                'status' => 'suspended',
                'is_super_admin' => true,
            ])
            ->assertRedirect();

        $employee->refresh();
        $this->assertSame('موظف تم تعديله', $employee->name);
        $this->assertSame('edited-staff', $employee->username);
        $this->assertSame('edited-staff@example.test', $employee->email);
        $this->assertSame('07980000222', $employee->phone);
        $this->assertSame($newProfile->id, $employee->permission_profile_id);
        $this->assertSame('admin', $employee->role);
        $this->assertSame('active', $employee->status);
        $this->assertFalse($employee->isSuperAdmin());

        $adminBefore = $superAdmin->fresh();
        $this->actingAs($superAdmin)
            ->put(route('admin.employees.update', $superAdmin), [
                'name' => $adminBefore->name,
                'username' => $adminBefore->username,
                'email' => $adminBefore->email,
                'phone' => $adminBefore->phone,
                'permission_profile_id' => $newProfile->id,
            ])
            ->assertStatus(422);
        $this->assertNull($superAdmin->fresh()->permission_profile_id);

        $merchant = User::query()->where('role', 'merchant')->firstOrFail();
        $this->actingAs($superAdmin)
            ->put(route('admin.employees.update', $merchant), [])
            ->assertNotFound();
    }

    public function test_staff_status_and_deletion_are_safe_and_revoke_tokens(): void
    {
        $superAdmin = $this->superAdministrator();
        $profile = $this->profile('مشغل التقارير', ['reports' => ['view']]);
        $employee = $this->employee('removable-staff', $profile);
        $employee->createToken('employee-test-token');

        $this->actingAs($superAdmin)
            ->patch(route('admin.employees.status', $employee), ['status' => 'suspended'])
            ->assertRedirect();
        $this->assertSame('suspended', $employee->fresh()->status);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $employee->id]);

        $this->actingAs($superAdmin)
            ->patch(route('admin.employees.status', $employee), ['status' => 'active'])
            ->assertRedirect();
        $this->assertSame('active', $employee->fresh()->status);

        $this->actingAs($superAdmin)
            ->patch(route('admin.employees.status', $superAdmin), ['status' => 'suspended'])
            ->assertStatus(422);
        $this->assertSame('active', $superAdmin->fresh()->status);

        $this->actingAs($superAdmin)
            ->delete(route('admin.employees.destroy', $superAdmin))
            ->assertStatus(422);
        $this->assertTrue($superAdmin->fresh()->isSuperAdmin());

        $employee->createToken('employee-delete-token');
        $this->actingAs($superAdmin)
            ->delete(route('admin.employees.destroy', $employee))
            ->assertRedirect();
        $this->assertSoftDeleted('users', ['id' => $employee->id]);
        $this->assertSame('suspended', User::withTrashed()->findOrFail($employee->id)->status);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $employee->id]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $superAdmin->id,
            'action' => 'dashboard.employee.deleted',
            'subject_type' => User::class,
            'subject_id' => $employee->id,
        ]);

        $courier = User::query()->where('role', 'courier')->firstOrFail();
        $this->actingAs($superAdmin)
            ->delete(route('admin.employees.destroy', $courier))
            ->assertNotFound();
    }

    private function superAdministrator(): User
    {
        return User::query()->where('username', 'admin')->firstOrFail();
    }

    /** @param array<string, array<int, string>> $permissions */
    private function profile(string $name, array $permissions): DashboardPermissionProfile
    {
        return DashboardPermissionProfile::create([
            'name' => $name,
            'permissions' => $permissions,
        ]);
    }

    private function employee(string $slug, DashboardPermissionProfile $profile): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create([
            'tenant_id' => Tenant::platform()->id,
            'name' => "موظف {$sequence}",
            'username' => $slug.'-'.$sequence,
            'email' => $slug.'-'.$sequence.'@example.test',
            'phone' => '0798100'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'password' => 'Password123!',
            'role' => 'admin',
            'status' => 'active',
            'permission_profile_id' => $profile->id,
            'is_super_admin' => false,
        ]);
    }
}
