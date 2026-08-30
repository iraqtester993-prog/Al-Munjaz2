<?php

namespace Tests\Feature;

use App\Models\DashboardInvitation;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlatformConsoleTest extends TestCase
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

    public function test_platform_admin_can_manage_company_billing_and_operator_invitation(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $basic = Plan::where('slug', 'basic')->firstOrFail();

        $this->actingAs($admin)
            ->get('/dashboard/platform')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Platform')
                ->has('plans')
                ->has('companies')
                ->has('invoices')
                ->has('operators')
            );

        $this->actingAs($admin)
            ->post('/dashboard/platform/companies', [
                'name' => 'شركة الاختبار اللوجستية',
                'slug' => 'test-logistics',
                'plan_id' => $basic->id,
                'status' => 'trial',
                'billing_period' => 'monthly',
            ])
            ->assertRedirect();

        $company = Tenant::where('slug', 'test-logistics')->firstOrFail();
        $subscription = Subscription::where('tenant_id', $company->id)->firstOrFail();
        $this->assertSame('company', $company->kind);
        $this->assertSame('trial', $subscription->status);
        $this->assertDatabaseHas('invoices', [
            'tenant_id' => $company->id,
            'subscription_id' => $subscription->id,
            'status' => 'draft',
        ]);

        $this->actingAs($admin)
            ->post('/dashboard/platform/plans', [
                'slug' => 'growth',
                'name_ar' => 'نمو',
                'name_en' => 'Growth',
                'name_ku' => 'گەشە',
                'price' => 90000,
                'limits' => ['max_orders_month' => 5000, 'max_branches' => 12, 'max_users' => 90],
                'features' => ['api', 'pwa', 'reports'],
                'is_active' => true,
            ])
            ->assertRedirect();
        $growth = Plan::where('slug', 'growth')->firstOrFail();

        $this->actingAs($admin)
            ->post('/dashboard/platform/subscriptions', [
                'tenant_id' => $company->id,
                'plan_id' => $growth->id,
                'status' => 'active',
                'billing_period' => 'annual',
                'amount' => 900000,
                'auto_renew' => true,
                'create_invoice' => true,
            ])
            ->assertRedirect();

        $current = Subscription::where('tenant_id', $company->id)->latest('id')->firstOrFail();
        $this->assertSame('active', $current->status);
        $this->assertSame($growth->id, $current->plan_id);
        $this->assertSame('active', $company->fresh()->status);

        $this->actingAs($admin)
            ->post('/dashboard/platform/invoices', [
                'tenant_id' => $company->id,
                'subscription_id' => $current->id,
                'amount' => 45000,
                'note' => 'خدمة إضافية',
            ])
            ->assertRedirect();
        $invoice = Invoice::where('tenant_id', $company->id)->latest('id')->firstOrFail();

        $this->actingAs($admin)
            ->patch("/dashboard/platform/invoices/{$invoice->id}", ['status' => 'paid'])
            ->assertRedirect();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->paid_at);

        $this->actingAs($admin)
            ->post('/dashboard/platform/invitations', [
                'name' => 'مشغل اختبار',
                'email' => 'operator@example.test',
                'expires_in_days' => 7,
            ])
            ->assertRedirect()
            ->assertSessionHas('invite_link');
        $this->assertDatabaseHas('dashboard_invitations', [
            'email' => 'operator@example.test',
            'role' => 'admin',
        ]);
    }

    public function test_dashboard_invitation_creates_an_active_profileless_operator_once(): void
    {
        $token = 'test-dashboard-invitation-token';
        $invitation = DashboardInvitation::create([
            'name' => 'مدير مدعو',
            'email' => 'new-admin@example.test',
            'role' => 'admin',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
        ]);

        $this->get('/dashboard/invitations/'.$token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/AcceptDashboardInvitation')
                ->where('invitation.email', 'new-admin@example.test')
            );

        $this->post('/dashboard/invitations/'.$token, [
            'name' => 'مدير مدعو',
            'username' => 'platform-invite-admin',
            'phone' => '07999910001',
            'password' => 'StrongPassword123',
            'password_confirmation' => 'StrongPassword123',
        ])->assertRedirect('/dashboard/access-denied');

        $user = User::where('username', 'platform-invite-admin')->firstOrFail();
        $this->assertSame('admin', $user->role);
        $this->assertSame('active', $user->status);
        $this->assertFalse($user->isSuperAdmin());
        $this->assertNull($user->permission_profile_id);
        $this->assertSame(Tenant::PLATFORM_SLUG, $user->tenant->slug);
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertSame($user->id, $invitation->fresh()->accepted_by);

        // A profileless invitation is a real active account, but it cannot
        // access any cross-tenant dashboard response until a super admin
        // explicitly assigns a named permission profile.
        $this->actingAs($user)
            ->get('/dashboard/platform')
            ->assertForbidden();

        // The same public URL cannot mint another privileged account.
        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
        $this->post('/dashboard/invitations/'.$token, [
            'name' => 'محاولة ثانية',
            'username' => 'another-admin',
            'phone' => '07999910002',
            'password' => 'StrongPassword123',
            'password_confirmation' => 'StrongPassword123',
        ])->assertStatus(410);
        $this->assertDatabaseMissing('users', ['username' => 'another-admin']);
    }
}
