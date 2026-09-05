<?php

namespace Tests\Feature;

use App\Models\DashboardInvitation;
use App\Models\DashboardPermissionProfile;
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

    public function test_company_and_subscription_actions_cannot_implicitly_issue_bills(): void
    {
        $basic = Plan::where('slug', 'basic')->firstOrFail();
        $profile = DashboardPermissionProfile::create([
            'name' => 'منشئ شركات فقط',
            'permissions' => ['platform' => ['view', 'companies_create']],
        ]);
        $operator = User::create([
            'tenant_id' => Tenant::platform()->id,
            'name' => 'مشغل الشركات',
            'username' => 'company-only-operator',
            'email' => 'company-only@example.test',
            'phone' => '07991230001',
            'password' => 'Password123!',
            'role' => 'admin',
            'status' => 'active',
            'permission_profile_id' => $profile->id,
            'is_super_admin' => false,
        ]);

        $this->actingAs($operator)
            ->post('/dashboard/platform/companies', [
                'name' => 'شركة بلا فاتورة',
                'slug' => 'company-without-bill',
                'plan_id' => $basic->id,
                'status' => 'trial',
                'billing_period' => 'monthly',
            ])
            ->assertRedirect();

        $company = Tenant::where('slug', 'company-without-bill')->firstOrFail();
        $this->assertDatabaseMissing('subscriptions', ['tenant_id' => $company->id]);
        $this->assertDatabaseMissing('invoices', ['tenant_id' => $company->id]);

        $profile->update(['permissions' => ['platform' => ['view', 'subscriptions_create']]]);
        $operator->refresh();

        $this->actingAs($operator)
            ->post('/dashboard/platform/subscriptions', [
                'tenant_id' => $company->id,
                'plan_id' => $basic->id,
                'status' => 'active',
                'billing_period' => 'monthly',
                'auto_renew' => true,
                // The request may try to ask for a bill, but only the
                // independent invoices_create action authorizes that effect.
                'create_invoice' => true,
            ])
            ->assertRedirect();

        $subscription = Subscription::where('tenant_id', $company->id)->firstOrFail();
        $this->assertDatabaseMissing('invoices', ['subscription_id' => $subscription->id]);
    }

    public function test_platform_financial_read_permission_gates_monetary_console_data_without_breaking_plan_edits(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $basic = Plan::where('slug', 'basic')->firstOrFail();
        $company = Tenant::create([
            'plan_id' => $basic->id,
            'slug' => 'financial-read-company',
            'name' => 'شركة صلاحيات مالية',
            'kind' => 'company',
            'status' => 'active',
        ]);
        $subscription = Subscription::create([
            'tenant_id' => $company->id,
            'plan_id' => $basic->id,
            'status' => 'active',
            'billing_period' => 'monthly',
            'amount' => 123_000,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'next_invoice_at' => now()->addMonth(),
            'auto_renew' => true,
        ]);
        $invoice = Invoice::create([
            'tenant_id' => $company->id,
            'subscription_id' => $subscription->id,
            'created_by' => $admin->id,
            'number' => 'INV-FINANCIAL-READ',
            'status' => 'issued',
            'amount' => 64_000,
            'currency' => 'IQD',
            'issued_at' => now(),
            'due_at' => now()->addWeek(),
            'note' => 'ترتيب فوترة خاص',
        ]);

        $viewer = $this->platformOperator('platform-financial-viewer', ['view'], '07991231001');
        $viewerProps = $this->actingAs($viewer)
            ->get('/dashboard/platform')
            ->assertOk()
            ->inertiaPage()['props'];

        $this->assertFalse($viewerProps['canViewPlatformFinancials']);
        $this->assertFalse($viewerProps['canViewPlanPrices']);
        $this->assertArrayNotHasKey('monthly_revenue', $viewerProps['summary']);
        $this->assertArrayNotHasKey('outstanding', $viewerProps['summary']);
        $this->assertArrayNotHasKey('price', collect($viewerProps['plans'])->firstWhere('id', $basic->id));
        $this->assertArrayNotHasKey('price', collect($viewerProps['companies'])->firstWhere('id', $company->id)['plan']);
        $this->assertArrayNotHasKey('amount', collect($viewerProps['subscriptions'])->firstWhere('id', $subscription->id));
        $this->assertArrayNotHasKey('amount', collect($viewerProps['invoices'])->firstWhere('id', $invoice->id));
        $this->assertArrayNotHasKey('currency', collect($viewerProps['invoices'])->firstWhere('id', $invoice->id));
        $this->assertArrayNotHasKey('note', collect($viewerProps['invoices'])->firstWhere('id', $invoice->id));

        // A mutation-only operator is normalized to screen access, but must
        // still not inherit commercial reads merely by changing a status.
        $statusOperator = $this->platformOperator('platform-subscription-status', ['subscriptions_change_status'], '07991231002');
        $statusProps = $this->actingAs($statusOperator)
            ->get('/dashboard/platform')
            ->assertOk()
            ->inertiaPage()['props'];

        $this->assertTrue($statusProps['canChangeSubscriptionStatus']);
        $this->assertFalse($statusProps['canViewPlatformFinancials']);
        $this->assertFalse($statusProps['canViewPlanPrices']);
        $this->assertArrayNotHasKey('price', collect($statusProps['plans'])->firstWhere('id', $basic->id));
        $this->assertArrayNotHasKey('amount', collect($statusProps['subscriptions'])->firstWhere('id', $subscription->id));
        $this->assertArrayNotHasKey('amount', collect($statusProps['invoices'])->firstWhere('id', $invoice->id));

        // The one intentional exception is a plan editor's own current
        // price, needed to pre-fill its edit form. Billing amounts stay out.
        $planEditor = $this->platformOperator('platform-plan-editor', ['plans_edit'], '07991231003');
        $planEditorProps = $this->actingAs($planEditor)
            ->get('/dashboard/platform')
            ->assertOk()
            ->inertiaPage()['props'];

        $this->assertTrue($planEditorProps['canViewPlanPrices']);
        $this->assertFalse($planEditorProps['canViewPlatformFinancials']);
        $this->assertSame((int) $basic->price, collect($planEditorProps['plans'])->firstWhere('id', $basic->id)['price']);
        $this->assertArrayNotHasKey('monthly_revenue', $planEditorProps['summary']);
        $this->assertArrayNotHasKey('amount', collect($planEditorProps['subscriptions'])->firstWhere('id', $subscription->id));
        $this->assertArrayNotHasKey('amount', collect($planEditorProps['invoices'])->firstWhere('id', $invoice->id));

        $financialViewer = $this->platformOperator('platform-financial-viewer-full', ['view_financial'], '07991231004');
        $financialProps = $this->actingAs($financialViewer)
            ->get('/dashboard/platform')
            ->assertOk()
            ->inertiaPage()['props'];

        $this->assertTrue($financialProps['canViewPlatformFinancials']);
        $this->assertTrue($financialProps['canViewPlanPrices']);
        $this->assertArrayHasKey('monthly_revenue', $financialProps['summary']);
        $this->assertArrayHasKey('outstanding', $financialProps['summary']);
        $this->assertSame((int) $basic->price, collect($financialProps['plans'])->firstWhere('id', $basic->id)['price']);
        $this->assertSame(123_000, collect($financialProps['subscriptions'])->firstWhere('id', $subscription->id)['amount']);
        $this->assertSame(64_000, collect($financialProps['invoices'])->firstWhere('id', $invoice->id)['amount']);
        $this->assertSame('ترتيب فوترة خاص', collect($financialProps['invoices'])->firstWhere('id', $invoice->id)['note']);
    }

    /** @param array<int, string> $permissions */
    private function platformOperator(string $username, array $permissions, string $phone): User
    {
        $profile = DashboardPermissionProfile::create([
            'name' => $username,
            'permissions' => DashboardPermissionProfile::normalizePermissions(['platform' => $permissions]),
        ]);

        return User::create([
            'tenant_id' => Tenant::platform()->id,
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
}
