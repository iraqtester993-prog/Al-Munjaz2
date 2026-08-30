<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Cashbox;
use App\Models\BranchMembership;
use App\Models\Order;
use App\Models\Province;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Inertia\Inertia;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        $canCreateBranches = $request->user()->canUseAdminPermission('branches', 'create');
        $canUpdateBranches = $request->user()->canUseAdminPermission('branches', 'update');
        $canManageBranches = $canCreateBranches || $canUpdateBranches;

        $branchQuery = $this->networkBranches()
            ->with('province:id,name_ar,name_en,name_ku')
            ->withCount([
                'users',
                'originOrders as outbound_orders_count',
                'destinationOrders as inbound_orders_count',
            ]);

        // Branch accounts and their legacy dashboard permissions are only
        // needed when provisioning or changing branch access. A read-only
        // branch viewer should not receive staff usernames or privilege maps.
        if ($canManageBranches) {
            $branchQuery->with([
                'members' => fn ($members) => $members->select('users.id', 'users.name', 'users.username', 'users.email', 'users.role', 'users.status'),
            ]);
        }

        $branches = $branchQuery
            ->orderBy('name_ar')
            ->get()
            ->map(function (Branch $branch) use ($canManageBranches): array {
                $payload = [
                'id' => $branch->id,
                'code' => $branch->code,
                'name_ar' => $branch->name_ar,
                'name_en' => $branch->name_en,
                'name_ku' => $branch->name_ku,
                'city' => $branch->city,
                'province_id' => $branch->province_id,
                'province' => $branch->province ? [
                    'id' => $branch->province->id,
                    'name_ar' => $branch->province->name_ar,
                    'name_en' => $branch->province->name_en,
                    'name_ku' => $branch->province->name_ku,
                ] : null,
                'phone' => $branch->phone,
                'address' => $branch->address,
                'cash_balance' => $branch->cash_balance,
                'is_active' => $branch->is_active,
                'users_count' => $branch->users_count,
                // The route may contain a branch at either end. Keep these
                // counts separate so operations never mistake arrivals for
                // departures (or count a cross-branch route twice).
                'outbound_orders_count' => $branch->outbound_orders_count,
                'inbound_orders_count' => $branch->inbound_orders_count,
                ];

                if ($canManageBranches) {
                    // Passwords are intentionally never returned after the
                    // one-time creation flash. The dashboard may still audit
                    // which access accounts have been granted to this branch.
                    $payload['access_accounts'] = $branch->members->map(fn (User $user) => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'username' => $user->username,
                        'email' => $user->email,
                        'role' => $user->pivot?->access_role === BranchMembership::OWNER ? 'owner' : 'branch_manager',
                        'status' => $user->status,
                        'permissions' => $user->dashboard_permissions ?? [],
                    ])->values();
                }

                return $payload;
            });

        $props = [
            'branches' => $branches,
            'canCreateBranches' => $canCreateBranches,
            'canUpdateBranches' => $canUpdateBranches,
            'canManageBranches' => $canManageBranches,
        ];

        if ($canManageBranches) {
            $platformTenant = Tenant::platform();
            // A platform owner may be explicitly attached to more than one
            // branch. Only already-active dashboard accounts from the
            // platform tenant are exposed for that safe assignment.
            $props['accessUsers'] = User::query()
                ->where('tenant_id', $platformTenant->id)
                ->where('status', 'active')
                ->whereIn('role', ['owner', 'branch_manager'])
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name', 'username', 'email', 'role', 'dashboard_permissions'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'role' => $user->role,
                    'permissions' => $user->dashboard_permissions ?? [],
                ])
                ->values();
            $props['provinces'] = Province::query()
                ->platform()
                ->active()
                ->orderBy('sort_order')
                ->orderBy('name_ar')
                ->get(['id', 'name_ar', 'name_en', 'name_ku', 'is_active'])
                ->values();
            $props['dashboardPermissions'] = User::DASHBOARD_PERMISSIONS;
        }

        return Inertia::render('Admin/Branches', $props);
    }

    public function store(Request $request)
    {
        $platformTenant = Tenant::platform();
        $branch = null;
        $credentials = [];

        \DB::transaction(function () use ($request, $platformTenant, &$branch, &$credentials): void {
            $branch = Branch::withoutGlobalScope(TenantScope::class)->create($this->validatedBranchData($request) + [
                'tenant_id' => $platformTenant->id,
                'is_platform_managed' => true,
                'is_active' => true,
            ]);

            // A governorate cannot be operational without a local dashboard
            // account. This account is always newly provisioned rather than
            // accepting a browser-selected existing account, so its single
            // membership remains the authorisation boundary for its branch.
            $credentials = $this->createGeneratedBranchManager($branch, $platformTenant);
        });

        $this->record($request, $branch, 'branch.created', ['is_active' => true]);

        $response = back()->with('success', __('Branch created successfully.'));

        return $response->with('branch_credentials', $credentials);
    }

    /**
     * Add an explicitly scoped dashboard account to an existing operational
     * branch.  This does not grant any access to the platform-wide dashboard.
     */
    public function storeAccess(Request $request, int $branch)
    {
        $branch = $this->findNetworkBranch($branch);
        $platformTenant = Tenant::platform();

        if ($request->filled('existing_user_id')) {
            $user = \DB::transaction(fn () => $this->grantExistingBranchAccess($request, $branch, $platformTenant));

            $this->record($request, $branch, 'branch.access_account_granted', [
                'user_id' => $user->id,
                'role' => $user->role,
                'username' => $user->username,
            ]);

            return back()->with('success', __('The existing dashboard account now has access to this branch.'));
        }

        $credentials = \DB::transaction(fn () => $this->createBranchAccessAccount($request, $branch, $platformTenant));

        $this->record($request, $branch, 'branch.access_account_created', [
            'user_id' => $credentials['user_id'],
            'role' => $credentials['role'],
            'username' => $credentials['username'],
            'email' => $credentials['email'],
        ]);

        return back()
            ->with('success', __('Branch dashboard account created successfully.'))
            ->with('branch_credentials', $credentials);
    }

    public function update(Request $request, int $branch)
    {
        $branch = $this->findNetworkBranch($branch);
        $before = $branch->only(['code', 'name_ar', 'name_en', 'name_ku', 'city', 'province_id', 'phone', 'address']);

        $branch->update($this->validatedBranchData($request, $branch));

        $this->record($request, $branch, 'branch.updated', [
            'before' => $before,
            'after' => $branch->only(array_keys($before)),
        ]);

        return back()->with('success', __('Branch updated successfully.'));
    }

    public function status(Request $request, int $branch)
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $branch = $this->findNetworkBranch($branch);
        $isActive = (bool) $data['is_active'];

        if (! $isActive && $branch->is_active && $this->hasOpenRouteOrders($branch)) {
            return back()->withErrors([
                'is_active' => __('An active order is still assigned to this branch. Reassign it before deactivating the branch.'),
            ]);
        }

        $branch->update(['is_active' => $isActive]);
        // A branch cashbox cannot be operated while its branch is inactive.
        // Keep this in step with the branch even when the cashbox was created
        // by an earlier release.
        Cashbox::withoutGlobalScope(TenantScope::class)
            ->where('branch_id', $branch->id)
            ->update(['is_active' => $isActive]);
        $this->record($request, $branch, 'branch.status_updated', ['is_active' => $isActive]);

        return back()->with('success', __('Branch status updated successfully.'));
    }

    /** @return Builder<Branch> */
    private function networkBranches(): Builder
    {
        return Branch::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', Tenant::platform()->id)
            ->where('is_platform_managed', true);
    }

    private function findNetworkBranch(int $id): Branch
    {
        return $this->networkBranches()->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function validatedBranchData(Request $request, ?Branch $branch = null): array
    {
        foreach (['code', 'name_ar', 'name_en', 'name_ku', 'city', 'phone', 'address'] as $field) {
            if (is_string($request->input($field))) {
                $value = trim((string) $request->input($field));
                $request->merge([$field => $field === 'code' ? strtoupper($value) : $value]);
            }
        }

        $platformTenant = Tenant::platform();
        $uniqueCode = Rule::unique('branches', 'code')
            ->where('tenant_id', $platformTenant->id);
        // The mobile sign-in deliberately asks for a governorate, not an
        // arbitrary branch name. Keep one active network branch per province
        // so selecting Baghdad always resolves to the Baghdad branch without
        // an unsafe hidden default.
        $uniqueProvince = Rule::unique('branches', 'province_id')
            ->where('tenant_id', $platformTenant->id)
            ->where('is_platform_managed', true)
            ->whereNull('deleted_at');

        if ($branch) {
            $uniqueCode->ignore($branch->id);
            $uniqueProvince->ignore($branch->id);
        }

        $provinceExists = Rule::exists('provinces', 'id')->whereNull('tenant_id');
        // A historic branch may retain its disabled governorate long enough
        // for an operator to correct its name or contact details. New branch
        // assignments (and a move to another governorate) must be active.
        if (! $branch || (int) $request->input('province_id') !== (int) $branch->province_id) {
            $provinceExists->where('is_active', true);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', $uniqueCode],
            'name_ar' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'name_ku' => ['nullable', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:60'],
            'province_id' => ['required', 'integer', $provinceExists, $uniqueProvince],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        return $data;
    }

    /**
     * Provision the one local manager that is created together with every
     * operating branch. Its email is a dashboard login identifier, not an
     * invitation or a recoverable secret; only the one-time flash response
     * contains the plaintext password.
     *
     * @return array{user_id:int,branch_id:int,branch_name:string,province_id:int,province:array{id:int,name_ar:string,name_en:?string,name_ku:?string}|null,role:string,username:string,email:string,password:string,login_url:string}
     */
    private function createGeneratedBranchManager(Branch $branch, Tenant $platformTenant): array
    {
        $branch->loadMissing('province:id,name_ar,name_en,name_ku');

        $username = $this->nextBranchUsername($branch->code);
        $email = $this->nextBranchEmail($branch->code);
        $password = $this->generatedPassword();
        $manager = User::create([
            'tenant_id' => $platformTenant->id,
            'branch_id' => $branch->id,
            'name' => Str::limit('مدير '.($branch->name_ar ?: $branch->code), 120, ''),
            'username' => $username,
            'email' => $email,
            // Dashboard accounts use email/password, so this is an internal
            // unique contact marker rather than a real person's phone number.
            'phone' => 'branch-manager-'.$branch->id,
            'password' => $password,
            'role' => 'branch_manager',
            'status' => 'active',
            'locale' => 'ar',
            'theme' => 'light',
            'dashboard_permissions' => $this->resolvedPermissions('branch_manager', null),
        ]);

        $branch->members()->attach($manager->id, [
            'access_role' => BranchMembership::MANAGER,
        ]);

        return $this->branchCredentials($manager, $branch, $password);
    }

    /**
     * Create a least-privilege account whose only dashboard visibility is the
     * one branch membership below. The plaintext password exists solely in
     * the immediate Inertia flash response; it is never stored in a setting,
     * activity payload, or API response.
     *
     * @return array{user_id:int,branch_id:int,branch_name:string,province_id:int,province:array{id:int,name_ar:string,name_en:?string,name_ku:?string}|null,role:string,username:string,email:string,password:string,login_url:string}
     */
    private function createBranchAccessAccount(Request $request, Branch $branch, Tenant $platformTenant): array
    {
        foreach (['access_name', 'access_phone', 'access_username', 'access_email'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => trim((string) $request->input($field))]);
            }
        }

        $data = $request->validate([
            'access_name' => ['required', 'string', 'max:120'],
            'access_phone' => ['required', 'string', 'max:30', Rule::unique('users', 'phone')],
            'access_username' => ['nullable', 'string', 'alpha_dash', 'min:3', 'max:60', Rule::unique('users', 'username')],
            'access_email' => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')],
            'access_password' => ['nullable', 'string', 'min:10', 'max:120'],
            'access_role' => ['required', Rule::in(['owner', 'branch_manager'])],
            'access_permissions' => ['nullable', 'array'],
            'access_permissions.*' => [Rule::in(User::DASHBOARD_PERMISSIONS)],
        ]);

        $username = filled($data['access_username'] ?? null)
            ? $data['access_username']
            : $this->nextBranchUsername($branch->code);
        $email = filled($data['access_email'] ?? null)
            ? $data['access_email']
            : $this->nextBranchEmail($branch->code);
        $password = filled($data['access_password'] ?? null)
            ? $data['access_password']
            : $this->generatedPassword();
        $role = $data['access_role'];

        $user = User::create([
            'tenant_id' => $platformTenant->id,
            'branch_id' => $branch->id,
            'name' => $data['access_name'],
            'username' => $username,
            'email' => $email,
            'phone' => $data['access_phone'],
            'password' => $password,
            'role' => $role,
            'status' => 'active',
            'locale' => 'ar',
            'theme' => 'light',
            'dashboard_permissions' => $this->resolvedPermissions($data['access_role'], $data['access_permissions'] ?? null),
        ]);

        $branch->members()->attach($user->id, [
            'access_role' => $role === 'owner' ? BranchMembership::OWNER : BranchMembership::MANAGER,
        ]);

        return $this->branchCredentials($user, $branch, $password);
    }

    /**
     * Attach a pre-existing dashboard account to a second (or later) branch.
     * The role is immutable here: a branch manager cannot be promoted to an
     * owner by changing a browser payload, and the user must belong to the
     * platform tenant rather than a merchant tenant.
     */
    private function grantExistingBranchAccess(Request $request, Branch $branch, Tenant $platformTenant): User
    {
        $data = $request->validate([
            'existing_user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'access_role' => ['required', Rule::in(['owner', 'branch_manager'])],
            'access_permissions' => ['nullable', 'array'],
            'access_permissions.*' => [Rule::in(User::DASHBOARD_PERMISSIONS)],
        ]);

        $user = User::query()
            ->whereKey($data['existing_user_id'])
            ->where('tenant_id', $platformTenant->id)
            ->where('status', 'active')
            ->where('role', $data['access_role'])
            ->first();

        if (! $user) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'existing_user_id' => [__('Choose an active platform branch account with the selected role.')],
            ]);
        }

        BranchMembership::query()->updateOrCreate([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
        ], [
            'access_role' => $user->role === 'owner'
                ? BranchMembership::OWNER
                : BranchMembership::MANAGER,
        ]);

        if (array_key_exists('access_permissions', $data)) {
            $user->update([
                'dashboard_permissions' => $this->resolvedPermissions($user->role, $data['access_permissions']),
            ]);
        }

        return $user;
    }

    private function nextBranchUsername(string $branchCode): string
    {
        $slug = Str::lower(Str::slug($branchCode));
        $base = Str::limit('branch-'.$slug, 50, '');
        $candidate = $base !== 'branch-' ? $base : 'branch-access';
        $suffix = 1;

        while (User::withTrashed()->where('username', $candidate)->exists()) {
            $suffix++;
            $candidate = Str::limit($base, 56 - strlen((string) $suffix), '').'-'.$suffix;
        }

        return $candidate;
    }

    private function nextBranchEmail(string $branchCode): string
    {
        $slug = Str::lower(Str::slug($branchCode));
        $domain = Str::lower(trim((string) config('app.product_domain', 'almunjaz.local')));

        // The configured product domain is deployment-controlled. Keep a
        // valid local fallback so provisioning never emits an unusable email
        // if a local development config has not supplied one yet.
        if (! filter_var('manager@'.$domain, FILTER_VALIDATE_EMAIL)) {
            $domain = 'almunjaz.local';
        }

        $maxLocalLength = max(3, min(64, 190 - strlen($domain) - 1));
        $base = Str::limit('branch-manager-'.($slug ?: 'access'), $maxLocalLength, '');
        $candidate = $base.'@'.$domain;
        $suffix = 1;

        while (User::withTrashed()->where('email', $candidate)->exists()) {
            $suffix++;
            $suffixText = '-'.$suffix;
            $local = Str::limit($base, max(1, $maxLocalLength - strlen($suffixText)), '').$suffixText;
            $candidate = $local.'@'.$domain;
        }

        return $candidate;
    }

    private function generatedPassword(): string
    {
        // Laravel's generator uses random_int and guarantees a mixture of
        // letters, numbers, and symbols. The plaintext is shown exactly once.
        return Str::password(20, true, true, true, false);
    }

    /**
     * @return array{user_id:int,branch_id:int,branch_name:string,province_id:int,province:array{id:int,name_ar:string,name_en:?string,name_ku:?string}|null,role:string,username:string,email:string,password:string,login_url:string}
     */
    private function branchCredentials(User $user, Branch $branch, string $password): array
    {
        $branch->loadMissing('province:id,name_ar,name_en,name_ku');

        return [
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'branch_name' => $branch->name_ar ?: $branch->code,
            'province_id' => (int) $branch->province_id,
            'province_name' => $branch->province?->name_ar,
            'province' => $branch->province ? [
                'id' => $branch->province->id,
                'name_ar' => $branch->province->name_ar,
                'name_en' => $branch->province->name_en,
                'name_ku' => $branch->province->name_ku,
            ] : null,
            'role' => $user->role,
            'username' => $user->username,
            'email' => $user->email,
            'password' => $password,
            'login_url' => url('/dashboard/login'),
        ];
    }

    /** @param array<int, string>|null $requested */
    private function resolvedPermissions(string $role, ?array $requested): array
    {
        if ($role === 'owner') {
            return User::DASHBOARD_PERMISSIONS;
        }

        $permissions = $requested ?? [
            'overview', 'orders', 'merchants', 'couriers',
            'courier_locations', 'content', 'notifications',
        ];

        return array_values(array_unique(array_intersect($permissions, User::DASHBOARD_PERMISSIONS)));
    }

    private function hasOpenRouteOrders(Branch $branch): bool
    {
        return Order::withoutGlobalScope(TenantScope::class)
            ->whereNotIn('status', ['delivered', 'returned', 'cancelled', 'damaged'])
            ->where(function (Builder $orders) use ($branch): void {
                $orders
                    ->where('origin_branch_id', $branch->id)
                    ->orWhere('destination_branch_id', $branch->id);
            })
            ->exists();
    }

    /** @param array<string, mixed> $data */
    private function record(Request $request, Branch $branch, string $action, array $data): void
    {
        ActivityLog::create([
            'tenant_id' => $branch->tenant_id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => 'branch',
            'subject_id' => $branch->id,
            'data' => $data,
            'ip' => $request->ip(),
        ]);
    }
}
