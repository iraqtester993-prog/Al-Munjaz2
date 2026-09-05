<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\DashboardInvitation;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Scopes\TenantScope;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminPlatformController extends Controller
{
    /**
     * The platform console intentionally has no tenant filter. It is the
     * Super Admin view from the dashboard reference: companies, plans,
     * subscriptions, invoices, and dashboard operators live in one audited
     * area while operational pages continue to focus on delivery work.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $canManageOperators = $user->isSuperAdmin();
        $canCreateCompanies = $user->canUseAdminPermission('platform', 'companies_create');
        $canEditCompanies = $user->canUseAdminPermission('platform', 'companies_edit');
        $canCreatePlans = $user->canUseAdminPermission('platform', 'plans_create');
        $canEditPlans = $user->canUseAdminPermission('platform', 'plans_edit');
        $canCreateSubscriptions = $user->canUseAdminPermission('platform', 'subscriptions_create');
        $canChangeSubscriptionStatus = $user->canUseAdminPermission('platform', 'subscriptions_change_status');
        $canCreateInvoices = $user->canUseAdminPermission('platform', 'invoices_create');
        $canChangeInvoiceStatus = $user->canUseAdminPermission('platform', 'invoices_change_status');
        // Reading the commercial side of the SaaS platform is intentionally
        // separate from operating it. A staff member may create a company,
        // change a subscription state, or issue a new invoice without seeing
        // every plan price, historic amount, revenue total, or balance.
        $canViewPlatformFinancials = $user->canUseAdminPermission('platform', 'view_financial');
        // Plan editors need the current price to edit that plan. This is the
        // sole non-financial-read exception and never unlocks invoice,
        // subscription, revenue, or balance amounts elsewhere in the page.
        $canViewPlanPrices = $canViewPlatformFinancials || $canEditPlans;
        // Legacy props are retained for cached browser pages. All current
        // buttons receive one of the explicit capabilities below.
        $canCreatePlatform = $canCreateCompanies || $canCreatePlans || $canCreateSubscriptions || $canCreateInvoices;
        $canUpdatePlatform = $canEditCompanies || $canEditPlans || $canChangeSubscriptionStatus || $canChangeInvoiceStatus;

        $monthUsage = Order::withoutGlobalScope(TenantScope::class)
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('tenant_id, COUNT(*) as total')
            ->groupBy('tenant_id')
            ->pluck('total', 'tenant_id');

        $subscriptionColumns = [
            'id', 'tenant_id', 'plan_id', 'status', 'billing_period',
            'starts_at', 'ends_at', 'next_invoice_at', 'auto_renew',
        ];
        if ($canViewPlatformFinancials) {
            $subscriptionColumns[] = 'amount';
        }
        $subscriptionPlanColumns = ['id', 'slug', 'name_ar', 'name_en', 'name_ku', 'limits', 'features', 'is_active'];
        if ($canViewPlatformFinancials) {
            $subscriptionPlanColumns[] = 'price';
        }

        $subscriptions = Subscription::query()
            ->select($subscriptionColumns)
            ->with([
                'tenant:id,name,slug,kind,status,plan_id',
                'plan' => fn ($query) => $query->select($subscriptionPlanColumns),
            ])
            ->latest('id')
            ->limit(160)
            ->get();

        $currentSubscriptionByTenant = $subscriptions
            ->filter(fn (Subscription $subscription) => in_array($subscription->status, ['trial', 'active'], true))
            ->groupBy('tenant_id')
            ->map(fn ($items) => $items->sortByDesc('starts_at')->first());

        $invoiceColumns = [
            'id', 'tenant_id', 'subscription_id', 'number',
            'status', 'issued_at', 'due_at', 'paid_at',
        ];
        $invoiceRelations = ['tenant:id,name,slug', 'subscription:id,plan_id,status'];
        if ($canViewPlatformFinancials) {
            $invoiceColumns[] = 'amount';
            $invoiceColumns[] = 'currency';
            // Billing notes can themselves contain an amount or a private
            // commercial arrangement, so keep them in the financial read
            // boundary as well.
            $invoiceColumns[] = 'note';
            $invoiceColumns[] = 'created_by';
            $invoiceRelations[] = 'creator:id,name';
        }

        $recentInvoices = Invoice::query()
            ->select($invoiceColumns)
            ->with($invoiceRelations)
            ->latest('id')
            ->limit(180)
            ->get();

        $outstandingByTenant = $recentInvoices
            ->filter(fn (Invoice $invoice) => in_array($invoice->status, ['issued', 'overdue'], true))
            ->groupBy('tenant_id')
            ->map(fn ($items) => $items->sortBy('due_at')->first());

        $companies = Tenant::query()
            ->where('slug', '!=', Tenant::PLATFORM_SLUG)
            ->whereIn('kind', ['company', 'merchant'])
            ->with([
                'plan' => function ($query) use ($canViewPlatformFinancials): void {
                    $columns = ['id', 'slug', 'name_ar', 'name_en', 'name_ku', 'limits', 'features', 'is_active'];
                    if ($canViewPlatformFinancials) {
                        $columns[] = 'price';
                    }

                    $query->select($columns);
                },
            ])
            ->withCount('users')
            // Branch itself is tenant scoped. The platform console must
            // explicitly remove that scope so an invited admin, which belongs
            // to the platform tenant, still sees each company's own count.
            ->withCount(['branches' => fn ($query) => $query->withoutGlobalScope(TenantScope::class)])
            ->orderBy('name')
            ->get()
            ->map(function (Tenant $tenant) use ($monthUsage, $currentSubscriptionByTenant, $outstandingByTenant, $canViewPlatformFinancials): array {
                $subscription = $currentSubscriptionByTenant->get($tenant->id);
                $invoice = $outstandingByTenant->get($tenant->id);
                $limits = $tenant->plan?->limits ?? [];

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'kind' => $tenant->kind,
                    'status' => $tenant->status,
                    'trial_ends_at' => $tenant->trial_ends_at?->toDateString(),
                    'plan' => $this->planData($tenant->plan, $canViewPlatformFinancials),
                    'users_count' => (int) $tenant->users_count,
                    'branches_count' => (int) $tenant->branches_count,
                    'orders_this_month' => (int) ($monthUsage[$tenant->id] ?? 0),
                    'order_limit' => $limits['max_orders_month'] ?? null,
                    'subscription' => $subscription ? $this->subscriptionData($subscription, $canViewPlatformFinancials) : null,
                    'next_invoice' => $invoice ? $this->invoiceData($invoice, $canViewPlatformFinancials) : null,
                ];
            })
            ->values();

        $planColumns = ['id', 'slug', 'name_ar', 'name_en', 'name_ku', 'limits', 'features', 'is_active'];
        if ($canViewPlanPrices) {
            $planColumns[] = 'price';
        }

        $plansQuery = Plan::query()
            ->select($planColumns)
            ->withCount(['tenants', 'subscriptions']);
        // Sorting a hidden numeric field would still disclose its relative
        // order. Keep the old price ordering only for someone entitled to
        // read prices; everyone else gets a stable non-financial ordering.
        if ($canViewPlanPrices) {
            $plansQuery->orderBy('price');
        } else {
            $plansQuery->orderBy('slug');
        }

        $plans = $plansQuery
            ->orderBy('id')
            ->get()
            ->map(fn (Plan $plan) => [
                ...$this->planData($plan, $canViewPlanPrices),
                'tenants_count' => (int) $plan->tenants_count,
                'subscriptions_count' => (int) $plan->subscriptions_count,
            ])
            ->values();

        $subscriptionRows = $subscriptions
            ->map(fn (Subscription $subscription) => $this->subscriptionData($subscription, $canViewPlatformFinancials))
            ->values();
        $invoiceRows = $recentInvoices
            ->map(fn (Invoice $invoice) => $this->invoiceData($invoice, $canViewPlatformFinancials))
            ->values();

        $summary = [
            'companies' => $companies->count(),
            'active_subscriptions' => $subscriptions->where('status', 'active')->count(),
            'trials' => $subscriptions->where('status', 'trial')->count(),
        ];
        if ($canViewPlatformFinancials) {
            $summary['monthly_revenue'] = (int) $subscriptions
                ->where('status', 'active')
                ->where('billing_period', 'monthly')
                ->sum('amount');
            $summary['outstanding'] = (int) $recentInvoices
                ->whereIn('status', ['issued', 'overdue'])
                ->sum('amount');
        }

        $props = [
            'summary' => $summary,
            'companies' => $companies,
            'plans' => $plans,
            'subscriptions' => $subscriptionRows,
            'invoices' => $invoiceRows,
            'canViewPlatformFinancials' => $canViewPlatformFinancials,
            'canViewPlanPrices' => $canViewPlanPrices,
            'canManageOperators' => $canManageOperators,
            'canCreatePlatform' => $canCreatePlatform,
            'canUpdatePlatform' => $canUpdatePlatform,
            'canCreateCompanies' => $canCreateCompanies,
            'canEditCompanies' => $canEditCompanies,
            'canCreatePlans' => $canCreatePlans,
            'canEditPlans' => $canEditPlans,
            'canCreateSubscriptions' => $canCreateSubscriptions,
            'canChangeSubscriptionStatus' => $canChangeSubscriptionStatus,
            'canCreateInvoices' => $canCreateInvoices,
            'canChangeInvoiceStatus' => $canChangeInvoiceStatus,
        ];

        // Dashboard-staff records and invitation metadata are not part of
        // the commercial platform view. Only a super administrator can see
        // (or provision) them.
        if ($canManageOperators) {
            $operators = User::withoutGlobalScopes()
                ->where('role', 'admin')
                ->with('tenant:id,name,slug')
                ->orderBy('status')
                ->orderBy('name')
                ->get(['id', 'tenant_id', 'name', 'username', 'email', 'phone', 'role', 'status', 'last_active_at', 'created_at'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'status' => $user->status,
                    'last_active_at' => $user->last_active_at?->toDateTimeString(),
                    'created_at' => $user->created_at?->toDateString(),
                    'tenant' => $user->tenant ? ['id' => $user->tenant->id, 'name' => $user->tenant->name] : null,
                ])
                ->values();

            $props['summary']['operators'] = $operators->count();
            $props['operators'] = $operators;
            $props['invitations'] = DashboardInvitation::query()
                ->with(['inviter:id,name', 'acceptedBy:id,name', 'permissionProfile:id,name'])
                ->latest('id')
                ->limit(80)
                ->get()
                ->map(fn (DashboardInvitation $invitation) => [
                    'id' => $invitation->id,
                    'name' => $invitation->name,
                    'email' => $invitation->email,
                    'role' => $invitation->role,
                    'expires_at' => $invitation->expires_at?->toDateTimeString(),
                    'accepted_at' => $invitation->accepted_at?->toDateTimeString(),
                    'invited_by' => $invitation->inviter?->name,
                    'accepted_by' => $invitation->acceptedBy?->name,
                    'permission_profile' => $invitation->permissionProfile ? [
                        'id' => $invitation->permissionProfile->id,
                        'name' => $invitation->permissionProfile->name,
                    ] : null,
                    'state' => $invitation->accepted_at ? 'accepted' : ($invitation->expires_at->isPast() ? 'expired' : 'pending'),
                ])
                ->values();
        }

        return Inertia::render('Admin/Platform', $props);
    }

    public function storeCompany(Request $request)
    {
        $canCreateInitialSubscription = $request->user()->canUseAdminPermission('platform', 'subscriptions_create');
        $canCreateInitialInvoice = $request->user()->canUseAdminPermission('platform', 'invoices_create');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash:ascii', 'min:3', 'max:80', Rule::unique('tenants', 'slug')],
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'status' => ['required', Rule::in(['trial', 'active', 'suspended'])],
            'billing_period' => ['required', Rule::in(Subscription::BILLING_PERIODS)],
            'trial_ends_at' => ['nullable', 'date'],
        ]);

        $plan = Plan::findOrFail($data['plan_id']);

        DB::transaction(function () use ($data, $plan, $request, $canCreateInitialSubscription, $canCreateInitialInvoice): void {
            $trialEndsAt = $data['status'] === 'trial'
                ? ($data['trial_ends_at'] ?? now()->addDays(14))
                : null;
            $tenant = Tenant::create([
                'plan_id' => $plan->id,
                'slug' => Str::lower($data['slug']),
                'name' => $data['name'],
                'kind' => 'company',
                'status' => $data['status'],
                'trial_ends_at' => $trialEndsAt,
            ]);

            // A company can be provisioned without giving the operator the
            // separate commercial powers to create its subscription or bill
            // it. When both capabilities are deliberately granted, preserve
            // the existing convenient initial setup workflow.
            $subscription = null;
            if ($canCreateInitialSubscription) {
                $subscription = $this->createSubscription(
                    $tenant,
                    $plan,
                    $data['status'],
                    $data['billing_period'],
                    null,
                );
            }

            if ($subscription && $canCreateInitialInvoice) {
                $this->createSubscriptionInvoice($subscription, $request->user(), $data['status'] === 'trial');
            }

            $this->log($request, 'platform.company.created', $tenant, [
                'plan_id' => $plan->id,
                'subscription_created' => $subscription !== null,
                'invoice_created' => $subscription !== null && $canCreateInitialInvoice,
            ]);
        });

        return back()->with('success', __('Platform company created.'));
    }

    public function updateCompany(Request $request, Tenant $tenant)
    {
        $this->guardCompany($tenant);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'status' => ['required', Rule::in(['trial', 'active', 'suspended'])],
            'trial_ends_at' => ['nullable', 'date'],
        ]);

        $tenant->update([
            'name' => $data['name'],
            'status' => $data['status'],
            'trial_ends_at' => $data['status'] === 'trial' ? ($data['trial_ends_at'] ?? $tenant->trial_ends_at) : null,
        ]);
        $this->log($request, 'platform.company.updated', $tenant, ['status' => $data['status']]);

        return back()->with('success', __('Platform changes saved.'));
    }

    public function storePlan(Request $request)
    {
        $data = $this->validatePlan($request);
        $plan = Plan::create($data);
        $this->log($request, 'platform.plan.created', $plan, ['slug' => $plan->slug]);

        return back()->with('success', __('Plan created successfully.'));
    }

    public function updatePlan(Request $request, Plan $plan)
    {
        $data = $this->validatePlan($request, $plan);
        unset($data['slug']);
        $plan->update($data);
        $this->log($request, 'platform.plan.updated', $plan, ['slug' => $plan->slug]);

        return back()->with('success', __('Plan updated successfully.'));
    }

    public function storeSubscription(Request $request)
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'integer', Rule::exists('tenants', 'id')],
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')],
            'status' => ['required', Rule::in(Subscription::STATUSES)],
            'billing_period' => ['required', Rule::in(Subscription::BILLING_PERIODS)],
            'amount' => ['nullable', 'integer', 'min:0'],
            'ends_at' => ['nullable', 'date'],
            'auto_renew' => ['required', 'boolean'],
            'create_invoice' => ['nullable', 'boolean'],
        ]);

        $tenant = Tenant::findOrFail($data['tenant_id']);
        $this->guardCompany($tenant);
        $plan = Plan::findOrFail($data['plan_id']);

        DB::transaction(function () use ($data, $tenant, $plan, $request): void {
            Subscription::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('status', ['trial', 'active', 'suspended'])
                ->update(['status' => 'expired', 'ends_at' => now()]);

            $subscription = $this->createSubscription(
                $tenant,
                $plan,
                $data['status'],
                $data['billing_period'],
                isset($data['amount']) ? (int) $data['amount'] : null,
                $data['ends_at'] ?? null,
                (bool) $data['auto_renew'],
            );

            // A subscription controls the company's dashboard access state.
            // That linked access effect is intentionally part of the
            // subscription action; creating an invoice remains an explicitly
            // separate action immediately below.
            $tenant->update([
                'plan_id' => $plan->id,
                'status' => in_array($data['status'], ['active', 'trial'], true) ? $data['status'] : 'suspended',
                'trial_ends_at' => $data['status'] === 'trial' ? $subscription->ends_at : null,
            ]);

            // Creating a subscription and issuing a bill are separate
            // authority decisions. Never let a request flag turn the former
            // into the latter without the invoices_create capability.
            if ($request->user()->canUseAdminPermission('platform', 'invoices_create')
                && ($data['create_invoice'] ?? true) === true) {
                $this->createSubscriptionInvoice($subscription, $request->user(), $data['status'] === 'trial');
            }
            $this->log($request, 'platform.subscription.created', $subscription, ['tenant_id' => $tenant->id, 'plan_id' => $plan->id]);
        });

        return back()->with('success', __('Subscription created and company access updated.'));
    }

    public function updateSubscriptionStatus(Request $request, Subscription $subscription)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Subscription::STATUSES)],
            'auto_renew' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($data, $subscription, $request): void {
            $subscription->update([
                'status' => $data['status'],
                'auto_renew' => (bool) $data['auto_renew'],
            ]);

            // Changing a subscription may change the linked company's access
            // state, but it never creates, settles, or exposes an invoice.
            if ($subscription->tenant && in_array($data['status'], ['active', 'trial', 'suspended'], true)) {
                $subscription->tenant->update([
                    'status' => in_array($data['status'], ['active', 'trial'], true) ? $data['status'] : 'suspended',
                    'trial_ends_at' => $data['status'] === 'trial' ? $subscription->ends_at : null,
                ]);
            }

            $this->log($request, 'platform.subscription.status_updated', $subscription, ['status' => $data['status']]);
        });

        return back()->with('success', __('Subscription status updated.'));
    }

    public function storeInvoice(Request $request)
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'integer', Rule::exists('tenants', 'id')],
            'subscription_id' => ['nullable', 'integer', Rule::exists('subscriptions', 'id')],
            'amount' => ['required', 'integer', 'min:0'],
            'due_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $tenant = Tenant::findOrFail($data['tenant_id']);
        $this->guardCompany($tenant);
        $subscription = isset($data['subscription_id'])
            ? Subscription::findOrFail($data['subscription_id'])
            : null;

        abort_unless(! $subscription || (int) $subscription->tenant_id === (int) $tenant->id, 422, __('The subscription does not belong to this company.'));

        $invoice = Invoice::create([
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription?->id,
            'created_by' => $request->user()->id,
            'number' => $this->nextInvoiceNumber(),
            'status' => 'issued',
            'amount' => (int) $data['amount'],
            'currency' => 'IQD',
            'issued_at' => now(),
            'due_at' => $data['due_at'] ?? now()->addDays(7),
            'note' => $data['note'] ?? null,
        ]);
        $this->log($request, 'platform.invoice.created', $invoice, ['tenant_id' => $tenant->id]);

        return back()->with('success', __('Invoice issued successfully.'));
    }

    public function updateInvoiceStatus(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['paid', 'overdue', 'void'])],
        ]);

        abort_if(in_array($invoice->status, ['paid', 'void'], true), 422, __('This invoice is already closed.'));

        $invoice->update([
            'status' => $data['status'],
            'paid_at' => $data['status'] === 'paid' ? now() : null,
        ]);
        $this->log($request, 'platform.invoice.status_updated', $invoice, ['status' => $data['status']]);

        return back()->with('success', __('Invoice status updated.'));
    }

    /**
     * This is deliberately a dashboard-admin invitation, not an untracked
     * link to a generic role. The invited account lands on the same protected
     * platform console with the role it was provisioned for.
     */
    public function invite(Request $request)
    {
        // An invitation can carry a named profile. Allowing a limited
        // platform operator to choose that profile would let them mint an
        // account stronger than their own, so provisioning dashboard staff
        // is deliberately a super-admin-only action.
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'expires_in_days' => ['required', 'integer', 'min:1', 'max:30'],
            // A profile is optional for backwards compatible invitation
            // links. A staff account without one is denied the dashboard
            // until a super administrator assigns it from Permissions.
            'permission_profile_id' => ['nullable', 'integer', Rule::exists('dashboard_permission_profiles', 'id')],
        ]);

        abort_if(User::withoutGlobalScopes()->where('email', $data['email'])->exists(), 422, __('A user already uses this email.'));
        abort_if(DashboardInvitation::query()
            ->where('email', $data['email'])
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->exists(), 422, __('A valid invitation already exists for this email.'));

        $token = Str::random(64);
        $invitation = DashboardInvitation::create([
            'invited_by' => $request->user()->id,
            'name' => $data['name'],
            'email' => Str::lower($data['email']),
            'role' => DashboardInvitation::ROLE,
            'permission_profile_id' => $data['permission_profile_id'] ?? null,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays((int) $data['expires_in_days']),
        ]);
        $this->log($request, 'platform.dashboard_invitation.created', $invitation, ['email' => $invitation->email]);

        $path = route('admin.invitations.accept', ['token' => $token], false);
        $inviteUrl = rtrim($request->getSchemeAndHttpHost(), '/').$path;

        return back()
            ->with('success', __('Dashboard invitation created. Copy the secure link before leaving this screen.'))
            ->with('invite_link', $inviteUrl);
    }

    public function invitationForm(string $token)
    {
        $invitation = $this->usableInvitation($token);

        return Inertia::render('Auth/AcceptDashboardInvitation', [
            'token' => $token,
            'invitation' => [
                'name' => $invitation->name,
                'email' => $invitation->email,
                'expires_at' => $invitation->expires_at->toDateTimeString(),
            ],
        ]);
    }

    public function acceptInvitation(Request $request, string $token)
    {
        $invitation = $this->usableInvitation($token);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'min:3', 'max:60', Rule::unique('users', 'username')],
            'phone' => ['required', 'string', 'max:30', Rule::unique('users', 'phone')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($data, $invitation): User {
            $locked = DashboardInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            abort_unless($locked->isUsable(), 422, __('This invitation is no longer available.'));

            $user = User::create([
                'tenant_id' => Tenant::platform()->id,
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $locked->email,
                'phone' => $data['phone'],
                'password' => $data['password'],
                'role' => DashboardInvitation::ROLE,
                'status' => 'active',
                'phone_verified_at' => now(),
                'permission_profile_id' => $locked->permission_profile_id,
            ]);

            $locked->update(['accepted_at' => now(), 'accepted_by' => $user->id]);

            ActivityLog::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'action' => 'platform.dashboard_invitation.accepted',
                'subject_type' => DashboardInvitation::class,
                'subject_id' => $locked->id,
                'data' => ['email' => $locked->email],
                'ip' => request()->ip(),
            ]);

            return $user;
        });

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->to($user->firstAdminDashboardPath() ?? '/dashboard/access-denied')
            ->with('success', __('Welcome to the platform dashboard.'));
    }

    /** @return array<string, mixed> */
    private function validatePlan(Request $request, ?Plan $plan = null): array
    {
        $slugRule = Rule::unique('plans', 'slug');
        if ($plan) {
            $slugRule = $slugRule->ignore($plan->id);
        }

        $data = $request->validate([
            'slug' => [$plan ? 'nullable' : 'required', 'alpha_dash:ascii', 'min:3', 'max:40', $slugRule],
            'name_ar' => ['required', 'string', 'max:80'],
            'name_en' => ['required', 'string', 'max:80'],
            'name_ku' => ['nullable', 'string', 'max:80'],
            'price' => ['required', 'integer', 'min:0'],
            'limits' => ['nullable', 'array'],
            'limits.max_orders_month' => ['nullable', 'integer', 'min:0'],
            'limits.max_branches' => ['nullable', 'integer', 'min:0'],
            'limits.max_users' => ['nullable', 'integer', 'min:0'],
            'limits.max_merchants' => ['nullable', 'integer', 'min:0'],
            'features' => ['nullable', 'array', 'max:30'],
            'features.*' => ['string', 'max:80'],
            'is_active' => ['required', 'boolean'],
        ]);

        $data['slug'] = isset($data['slug']) ? Str::lower($data['slug']) : null;
        $data['limits'] = collect($data['limits'] ?? [])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value) => (int) $value)
            ->all();
        $data['features'] = collect($data['features'] ?? [])
            ->map(fn ($feature) => trim((string) $feature))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $data;
    }

    private function createSubscription(
        Tenant $tenant,
        Plan $plan,
        string $status,
        string $billingPeriod,
        ?int $amount,
        ?string $endsAt = null,
        bool $autoRenew = true,
    ): Subscription {
        $startsAt = now();
        $ends = $endsAt
            ? Carbon::parse($endsAt)
            : ($billingPeriod === 'annual' ? $startsAt->copy()->addYear() : $startsAt->copy()->addMonth());
        $monthlyPrice = (int) $plan->price;
        $finalAmount = $amount ?? ($billingPeriod === 'annual' ? $monthlyPrice * 12 : $monthlyPrice);

        return Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => $status,
            'billing_period' => $billingPeriod,
            'amount' => $finalAmount,
            'starts_at' => $startsAt,
            'ends_at' => $ends,
            'next_invoice_at' => $ends,
            'auto_renew' => $autoRenew,
        ]);
    }

    private function createSubscriptionInvoice(Subscription $subscription, ?User $actor, bool $isTrial): Invoice
    {
        return Invoice::create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'created_by' => $actor?->id,
            'number' => $this->nextInvoiceNumber(),
            'status' => $isTrial ? 'draft' : 'issued',
            'amount' => $subscription->amount,
            'currency' => 'IQD',
            'issued_at' => $isTrial ? null : now(),
            'due_at' => $isTrial ? $subscription->ends_at : now()->addDays(7),
            'note' => $isTrial ? __('Trial subscription invoice.') : __('Subscription invoice.'),
        ]);
    }

    private function nextInvoiceNumber(): string
    {
        do {
            $number = 'INV-'.now()->format('ym').'-'.Str::upper(Str::random(6));
        } while (Invoice::withTrashed()->where('number', $number)->exists());

        return $number;
    }

    private function guardCompany(Tenant $tenant): void
    {
        abort_unless(
            $tenant->slug !== Tenant::PLATFORM_SLUG && in_array($tenant->kind, ['company', 'merchant'], true),
            404,
        );
    }

    private function usableInvitation(string $token): DashboardInvitation
    {
        $invitation = DashboardInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->firstOrFail();

        abort_unless($invitation->isUsable(), 410, __('This invitation is no longer available.'));

        return $invitation;
    }

    private function log(Request $request, string $action, mixed $subject, array $data = []): void
    {
        ActivityLog::create([
            'tenant_id' => $subject instanceof Tenant ? $subject->id : null,
            'user_id' => $request->user()?->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->id,
            'data' => $data,
            'ip' => $request->ip(),
        ]);
    }

    private function planData(?Plan $plan, bool $includePrice = false): ?array
    {
        if (! $plan) {
            return null;
        }

        $data = [
            'id' => $plan->id,
            'slug' => $plan->slug,
            'name_ar' => $plan->name_ar,
            'name_en' => $plan->name_en,
            'name_ku' => $plan->name_ku,
            'limits' => $plan->limits ?? [],
            'features' => $plan->features ?? [],
            'is_active' => (bool) $plan->is_active,
        ];

        if ($includePrice) {
            $data['price'] = (int) $plan->price;
        }

        return $data;
    }

    private function subscriptionData(Subscription $subscription, bool $includeFinancials = false): array
    {
        $data = [
            'id' => $subscription->id,
            'tenant_id' => $subscription->tenant_id,
            'tenant' => $subscription->tenant ? ['id' => $subscription->tenant->id, 'name' => $subscription->tenant->name, 'slug' => $subscription->tenant->slug] : null,
            // The plan reference is useful to select or operate a
            // subscription. Its price is a separate financial-read field.
            'plan' => $this->planData($subscription->plan, $includeFinancials),
            'status' => $subscription->status,
            'billing_period' => $subscription->billing_period,
            'starts_at' => $subscription->starts_at?->toDateString(),
            'ends_at' => $subscription->ends_at?->toDateString(),
            'next_invoice_at' => $subscription->next_invoice_at?->toDateString(),
            'auto_renew' => (bool) $subscription->auto_renew,
        ];

        if ($includeFinancials) {
            $data['amount'] = (int) $subscription->amount;
        }

        return $data;
    }

    private function invoiceData(Invoice $invoice, bool $includeFinancials = false): array
    {
        $data = [
            'id' => $invoice->id,
            'tenant_id' => $invoice->tenant_id,
            'tenant' => $invoice->tenant ? ['id' => $invoice->tenant->id, 'name' => $invoice->tenant->name, 'slug' => $invoice->tenant->slug] : null,
            'subscription_id' => $invoice->subscription_id,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'issued_at' => $invoice->issued_at?->toDateString(),
            'due_at' => $invoice->due_at?->toDateString(),
            'paid_at' => $invoice->paid_at?->toDateString(),
        ];

        if ($includeFinancials) {
            $data['amount'] = (int) $invoice->amount;
            $data['currency'] = $invoice->currency;
            $data['note'] = $invoice->note;
            $data['created_by'] = $invoice->creator?->name;
        }

        return $data;
    }
}
