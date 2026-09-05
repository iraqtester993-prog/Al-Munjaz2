<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BranchMembership;
use App\Models\Cashbox;
use App\Models\Order;
use App\Models\Province;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchDashboardAuthorization;
use App\Services\BranchDashboardContext;
use App\Services\BranchDashboardScope;
use App\Services\DashboardBranchFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $scope = $this->dashboardScope($request);
        $branchFilter = app(DashboardBranchFilter::class);
        $selectedBranchId = $branchFilter->selectedBranchId($request, $scope);
        $isBranchDashboard = $scope->requiresBranchScope();

        if ($isBranchDashboard) {
            $this->requireLocalBranchPermission($request, $scope, 'view');
        }

        // A principal branch manager operates the same dashboard shell, but
        // this screen is deliberately reduced to its one local branch. It
        // must never become a directory of the platform network, even if a
        // caller guesses a branch id or supplies an alternate filter.
        $canManageBranchAccess = ! $isBranchDashboard
            && $user->canUseAdminPermission('branches', 'manage_access');
        // The initial manager belongs only to the newly created branch. That
        // bounded bootstrap must not grant the much broader ability to
        // manage access accounts on every existing branch.
        $canCreateBranches = ! $isBranchDashboard
            && $user->canUseAdminPermission('branches', 'create');
        $canEditBranches = $isBranchDashboard
            ? app(BranchDashboardAuthorization::class)->allows($user, $scope, 'branches', 'edit')
            : $user->canUseAdminPermission('branches', 'edit');
        $canChangeBranchStatus = ! $isBranchDashboard
            && $user->canUseAdminPermission('branches', 'change_status');
        $canDeleteBranches = ! $isBranchDashboard
            && $user->canUseAdminPermission('branches', 'delete');
        // A branch's cached cash balance is financial data. Viewing the
        // branch network alone must not reveal it.
        $canViewBranchCashBalance = ! $isBranchDashboard
            && $user->canUseAdminPermission('finance', 'view_balances');
        $canUpdateBranches = $canEditBranches || $canChangeBranchStatus || $canManageBranchAccess || $canDeleteBranches;
        $canManageBranches = $canCreateBranches || $canUpdateBranches;

        $branchQuery = $this->networkBranches()
            ->with('province:id,name_ar,name_en,name_ku')
            ->withCount([
                'users',
                'originOrders as outbound_orders_count',
                'destinationOrders as inbound_orders_count',
            ]);

        if ($isBranchDashboard) {
            $branchQuery->whereKey($scope->branchId());
        } elseif ($selectedBranchId) {
            // The platform manager normally sees the entire reference
            // directory. When the super admin has selected a branch in the
            // shared dashboard filter, keep this page aligned with that
            // operational review context as well.
            $branchQuery->whereKey($selectedBranchId);
        }

        // Branch accounts and their legacy dashboard permissions are only
        // needed when provisioning or changing branch access. A read-only
        // branch viewer should not receive staff usernames or privilege maps.
        if ($canManageBranchAccess) {
            $branchQuery->with([
                'members' => fn ($members) => $members->select('users.id', 'users.name', 'users.phone', 'users.username', 'users.email', 'users.role', 'users.status', 'users.dashboard_permissions'),
            ]);
        }

        $branches = $branchQuery
            ->orderBy('name_ar')
            ->get()
            ->map(function (Branch $branch) use ($canManageBranchAccess, $canViewBranchCashBalance): array {
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
                    'email' => $branch->email,
                    'address' => $branch->address,
                    'is_active' => $branch->is_active,
                    'users_count' => $branch->users_count,
                    // The route may contain a branch at either end. Keep these
                    // counts separate so operations never mistake arrivals for
                    // departures (or count a cross-branch route twice).
                    'outbound_orders_count' => $branch->outbound_orders_count,
                    'inbound_orders_count' => $branch->inbound_orders_count,
                ];

                if ($canViewBranchCashBalance) {
                    $payload['cash_balance'] = $branch->cash_balance;
                }

                if ($canManageBranchAccess) {
                    // Passwords are intentionally never returned after the
                    // one-time creation flash. The dashboard may still audit
                    // which access accounts have been granted to this branch.
                    $payload['dashboard_login_url'] = $this->branchDashboardLoginUrl();
                    $payload['access_accounts'] = $branch->members->map(fn (User $user) => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'phone' => $user->phone,
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
            'canEditBranches' => $canEditBranches,
            'canChangeBranchStatus' => $canChangeBranchStatus,
            'canManageBranchAccess' => $canManageBranchAccess,
            'canDeleteBranches' => $canDeleteBranches,
            'canViewBranchCashBalance' => $canViewBranchCashBalance,
            'branchFilter' => $branchFilter->payload($request, $scope),
        ];

        if ($canManageBranchAccess) {
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
            $props['dashboardPermissions'] = User::DASHBOARD_PERMISSIONS;
        }

        if (! $isBranchDashboard && ($canCreateBranches || $canEditBranches)) {
            $props['provinces'] = Province::query()
                ->platform()
                ->active()
                ->orderBy('sort_order')
                ->orderBy('name_ar')
                ->get(['id', 'name_ar', 'name_en', 'name_ku', 'is_active'])
                ->values();
        }

        return Inertia::render('Admin/Branches', $props);
    }

    public function store(Request $request)
    {
        $scope = $this->dashboardScope($request);
        // Route middleware is the primary permission boundary. Keep this
        // controller check as a second boundary for direct calls and future
        // route changes: a local manager never provisions another branch.
        abort_if($scope->requiresBranchScope(), 403);
        abort_unless($request->user()->canUseAdminPermission('branches', 'create'), 403);

        $platformTenant = Tenant::platform();
        $branch = null;
        $credentials = [];
        $access = $this->validatedInitialBranchAccess($request);

        \DB::transaction(function () use ($request, $platformTenant, $access, &$branch, &$credentials): void {
            $branch = Branch::withoutGlobalScope(TenantScope::class)->create($this->validatedBranchData($request) + [
                'tenant_id' => $platformTenant->id,
                'is_platform_managed' => true,
                'is_active' => true,
            ]);

            // Every new branch has one explicit, isolated sign-in account.
            // Its membership is the authorisation boundary for this branch;
            // it can never inherit the platform-wide administrator view.
            $credentials = $this->createInitialBranchManager($branch, $platformTenant, $access);
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
        $scope = $this->dashboardScope($request);
        // Credentials and memberships are platform-owned identity controls,
        // not branch-local configuration. Do not expose or mutate even the
        // active branch's account list from this controller.
        abort_if($scope->requiresBranchScope(), 403);
        abort_unless($request->user()->canUseAdminPermission('branches', 'manage_access'), 403);

        $branch = $this->findNetworkBranch($branch, $scope);
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

    /**
     * Keep each branch login manageable by the platform owner without ever
     * returning an existing password. A new password may be assigned here;
     * its plaintext is returned in the one-time flash response only.
     */
    public function updateAccess(Request $request, int $branch, int $account)
    {
        $scope = $this->dashboardScope($request);
        abort_if($scope->requiresBranchScope(), 403);
        abort_unless($request->user()->canUseAdminPermission('branches', 'manage_access'), 403);

        $branch = $this->findNetworkBranch($branch, $scope);
        $user = $branch->members()->whereKey($account)->firstOrFail();

        foreach (['access_name', 'access_phone', 'access_username', 'access_email'] as $field) {
            if (is_string($request->input($field))) {
                $value = trim((string) $request->input($field));
                $request->merge([$field => $field === 'access_email' ? Str::lower($value) : $value]);
            }
        }

        $data = $request->validate([
            'access_name' => ['required', 'string', 'max:120'],
            'access_phone' => ['required', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($user->id)],
            'access_username' => ['required', 'string', 'alpha_dash', 'min:3', 'max:60', Rule::unique('users', 'username')->ignore($user->id)],
            'access_email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'access_password' => ['nullable', 'string', 'min:10', 'max:120'],
            'access_role' => ['required', Rule::in(['owner', 'branch_manager'])],
            'access_permissions' => ['nullable', 'array'],
            'access_permissions.*' => [Rule::in(User::DASHBOARD_PERMISSIONS)],
        ]);

        $permissions = $data['access_role'] === 'owner'
            ? User::DASHBOARD_PERMISSIONS
            : array_values(array_unique($data['access_permissions'] ?? []));
        $password = filled($data['access_password'] ?? null) ? $data['access_password'] : null;

        \DB::transaction(function () use ($branch, $user, $data, $permissions, $password): void {
            $attributes = [
                'name' => $data['access_name'],
                'phone' => $data['access_phone'],
                'username' => $data['access_username'],
                'email' => $data['access_email'],
                'role' => $data['access_role'],
                'dashboard_permissions' => $permissions,
            ];

            if ($password !== null) {
                $attributes['password'] = $password;
            }

            $user->update($attributes);
            $branch->members()->updateExistingPivot($user->id, [
                'access_role' => $data['access_role'] === 'owner' ? BranchMembership::OWNER : BranchMembership::MANAGER,
            ]);
        });

        $this->record($request, $branch, 'branch.access_account_updated', [
            'user_id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role,
            'password_reset' => $password !== null,
        ]);

        $response = back()->with('success', __('Branch dashboard account updated successfully.'));

        return $password === null
            ? $response
            : $response->with('branch_credentials', $this->branchCredentials($user->fresh(), $branch, $password));
    }

    public function update(Request $request, int $branch)
    {
        $scope = $this->dashboardScope($request);

        if ($scope->requiresBranchScope()) {
            $this->requireLocalBranchPermission($request, $scope, 'edit');
        } else {
            abort_unless($request->user()->canUseAdminPermission('branches', 'edit'), 403);
        }

        $branch = $this->findNetworkBranch($branch, $scope);
        $isBranchDashboard = $scope->requiresBranchScope();
        $before = $branch->only($isBranchDashboard
            ? ['name_ar', 'phone', 'email', 'address']
            : ['code', 'name_ar', 'name_en', 'name_ku', 'city', 'province_id', 'phone', 'email', 'address']);

        $branch->update($isBranchDashboard
            ? $this->validatedLocalBranchData($request)
            : $this->validatedBranchData($request, $branch));

        $this->record($request, $branch, 'branch.updated', [
            'before' => $before,
            'after' => $branch->only(array_keys($before)),
        ]);

        return back()->with('success', __('Branch updated successfully.'));
    }

    public function status(Request $request, int $branch)
    {
        $scope = $this->dashboardScope($request);
        abort_if($scope->requiresBranchScope(), 403);
        abort_unless($request->user()->canUseAdminPermission('branches', 'change_status'), 403);

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $branch = $this->findNetworkBranch($branch, $scope);
        $isActive = (bool) $data['is_active'];

        if ($isActive && ! $branch->is_active) {
            $provinceIsActive = $branch->province_id !== null
                && Province::query()
                    ->platform()
                    ->active()
                    ->whereKey($branch->province_id)
                    ->exists();

            if (! $provinceIsActive) {
                return back()->withErrors([
                    'is_active' => 'لا يمكن تفعيل الفرع قبل تفعيل محافظته في الإعدادات.',
                ]);
            }

            $hasActiveProvinceOwner = $this->networkBranches()
                ->where('is_active', true)
                ->where('province_id', $branch->province_id)
                ->where('id', '!=', $branch->id)
                ->exists();

            if ($hasActiveProvinceOwner) {
                return back()->withErrors([
                    'is_active' => 'يوجد فرع نشط آخر لهذه المحافظة. أوقفه أو انقله أولاً.',
                ]);
            }
        }

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

        if (! $isActive) {
            // Removing the persisted session makes a branch pause take effect
            // immediately even for an operator who already has the portal
            // open in another tab. The access account itself stays intact,
            // so activation restores it without re-provisioning credentials.
            $memberIds = BranchMembership::query()
                ->where('branch_id', $branch->id)
                ->pluck('user_id');

            if ($memberIds->isNotEmpty()) {
                \DB::table('sessions')->whereIn('user_id', $memberIds)->delete();
            }
        }
        $this->record($request, $branch, 'branch.status_updated', ['is_active' => $isActive]);

        return back()->with('success', __('Branch status updated successfully.'));
    }

    /**
     * Soft-delete an unused branch. Active routes keep their branch history,
     * so they must be reassigned or finished before deleting the branch.
     */
    public function destroy(Request $request, int $branch)
    {
        $scope = $this->dashboardScope($request);
        abort_if($scope->requiresBranchScope(), 403);
        abort_unless($request->user()->canUseAdminPermission('branches', 'delete'), 403);

        $branch = $this->findNetworkBranch($branch, $scope);

        if ($this->hasOpenRouteOrders($branch)) {
            return back()->withErrors([
                'branch' => 'لا يمكن حذف الفرع لوجود طلبات نشطة مرتبطة به. انقل الطلبات أو أكملها أولاً.',
            ]);
        }

        \DB::transaction(function () use ($request, $branch): void {
            // Detach access rather than removing staff accounts; a user can
            // still be assigned safely to another branch later.
            BranchMembership::where('branch_id', $branch->id)->delete();

            Cashbox::withoutGlobalScope(TenantScope::class)
                ->where('branch_id', $branch->id)
                ->update(['is_active' => false]);

            $this->record($request, $branch, 'branch.deleted', [
                'name' => $branch->name_ar,
                'province_id' => $branch->province_id,
            ]);

            $branch->delete();
        });

        return back()->with('success', 'تم حذف الفرع وإيقاف صناديقه وحسابات الوصول الخاصة به.');
    }

    /** @return Builder<Branch> */
    private function networkBranches(): Builder
    {
        return Branch::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', Tenant::platform()->id)
            ->where('is_platform_managed', true);
    }

    private function findNetworkBranch(int $id, ?BranchDashboardScope $scope = null): Branch
    {
        $query = $this->networkBranches();

        if ($scope?->requiresBranchScope()) {
            abort_unless($scope->hasBranchScope(), 403);
            $query->whereKey($scope->branchId());
        }

        return $query->findOrFail($id);
    }

    private function dashboardScope(Request $request): BranchDashboardScope
    {
        $scope = app(BranchDashboardContext::class)->fromRequest($request);

        if ($scope->requiresBranchScope()) {
            abort_unless($scope->hasBranchScope(), 403);
        }

        return $scope;
    }

    private function requireLocalBranchPermission(Request $request, BranchDashboardScope $scope, string $action): void
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User
                && $user->isActiveUser()
                && app(BranchDashboardAuthorization::class)->allows($user, $scope, 'branches', $action),
            403,
        );
    }

    /** @return array<string, mixed> */
    private function validatedBranchData(Request $request, ?Branch $branch = null): array
    {
        // Keep the small creation form focused on the information the
        // operator actually owns (name, province, phone and address).  The
        // optional legacy fields below are accepted only so an older
        // dashboard tab cannot fail halfway through a deployment.
        foreach (['code', 'name_ar', 'name_en', 'name_ku', 'city', 'phone', 'email', 'address'] as $field) {
            if (is_string($request->input($field))) {
                $value = trim((string) $request->input($field));
                $request->merge([$field => match ($field) {
                    'code' => strtoupper($value),
                    'email' => Str::lower($value),
                    default => $value,
                }]);
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

        // An inactive historical branch may retain (or be corrected to) a
        // province already served by the live branch. The uniqueness rule is
        // intentionally enforced only while this branch is operational.
        if (! $branch || $branch->is_active) {
            $uniqueProvince->where('is_active', true);
        }

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
            'code' => ['nullable', 'string', 'max:20', $uniqueCode],
            'name_ar' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'name_ku' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:60'],
            'province_id' => ['required', 'integer', $provinceExists, $uniqueProvince],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:191'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $province = Province::query()->findOrFail($data['province_id']);
        $data['name_en'] = filled($data['name_en'] ?? null) ? $data['name_en'] : $data['name_ar'];
        $data['name_ku'] = filled($data['name_ku'] ?? null) ? $data['name_ku'] : $data['name_ar'];
        $data['city'] = $province->name_ar;
        $data['code'] = $branch?->code
            ?: (filled($data['code'] ?? null) ? $data['code'] : $this->nextBranchCode($data['name_ar'], $platformTenant->id));

        return $data;
    }

    /**
     * The branch dashboard can maintain its displayed local details, but it
     * cannot move itself to another governorate, change the network code, or
     * alter its lifecycle. Those fields define shared routing and remain a
     * super-admin responsibility regardless of what the browser submits.
     *
     * @return array{name_ar:string,phone:?string,email:?string,address:?string}
     */
    private function validatedLocalBranchData(Request $request): array
    {
        foreach (['name_ar', 'phone', 'email', 'address'] as $field) {
            if (is_string($request->input($field))) {
                $value = trim((string) $request->input($field));
                $request->merge([$field => $field === 'email' ? Str::lower($value) : $value]);
            }
        }

        return $request->validate([
            'name_ar' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:191'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * The initial branch account is generated by default.  An administrator
     * may provide an email, username, or temporary password when a branch
     * has a pre-agreed credential, but leaving those values blank must never
     * block branch provisioning.
     *
     * @return array{access_username:?string,access_email:?string,access_password:?string}
     */
    private function validatedInitialBranchAccess(Request $request): array
    {
        foreach (['access_username', 'access_email'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => trim((string) $request->input($field))]);
            }
        }

        return $request->validate([
            'access_username' => ['nullable', 'string', 'alpha_dash', 'min:3', 'max:60', Rule::unique('users', 'username')],
            'access_email' => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')],
            'access_password' => ['nullable', 'string', 'min:8', 'max:120'],
        ]);
    }

    /**
     * Provision the one local manager that is created together with every
     * operating branch. Its email is a dashboard login identifier, not an
     * invitation or a recoverable secret; only the one-time flash response
     * contains the plaintext password.
     *
     * @return array{user_id:int,branch_id:int,branch_name:string,province_id:int,province:array{id:int,name_ar:string,name_en:?string,name_ku:?string}|null,role:string,username:string,email:string,password:string,login_url:string}
     */
    private function createInitialBranchManager(Branch $branch, Tenant $platformTenant, array $access): array
    {
        $branch->loadMissing('province:id,name_ar,name_en,name_ku');

        $username = filled($access['access_username'] ?? null)
            ? $access['access_username']
            : $this->nextBranchUsername($branch->code);
        $email = filled($access['access_email'] ?? null)
            ? $access['access_email']
            : $this->nextBranchEmail($branch->code);
        $password = filled($access['access_password'] ?? null)
            ? $access['access_password']
            : $this->generatedPassword();
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

        app(BranchDashboardContext::class)->assignPrimaryMembership($manager, $branch);

        // Preserve a distinct branch-contact field where supplied. Existing
        // creation flows generated only manager credentials, so use that
        // address as the safe contact fallback rather than leaving a new
        // operating branch without an email.
        if (blank($branch->email)) {
            $branch->update(['email' => $email]);
        }

        return $this->branchCredentials($manager, $branch, $password);
    }

    private function nextBranchCode(string $name, int $tenantId): string
    {
        $base = strtoupper((string) Str::slug($name, '-'));
        $base = $base !== '' ? Str::limit($base, 16, '') : 'BRANCH';
        $candidate = $base;
        $suffix = 1;

        while (Branch::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('code', $candidate)
            ->exists()) {
            $suffix++;
            $candidate = Str::limit($base, 20 - strlen((string) $suffix) - 1, '').'-'.$suffix;
        }

        return $candidate;
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

        app(BranchDashboardContext::class)->assignPrimaryMembership($user, $branch);

        return $this->branchCredentials($user, $branch, $password);
    }

    /**
     * Move a pre-existing dashboard account to this branch as its one
     * primary operating scope. Historical memberships may remain auditable,
     * but never expand what the account can see. The role is immutable here:
     * a branch manager cannot be promoted to an owner by changing a browser
     * payload, and the user must belong to the platform tenant rather than a
     * merchant tenant.
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
            throw ValidationException::withMessages([
                'existing_user_id' => [__('Choose an active platform branch account with the selected role.')],
            ]);
        }

        app(BranchDashboardContext::class)->assignPrimaryMembership($user, $branch);

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
            // `url()` follows APP_URL, which belongs to the mobile product
            // on production. Branch credentials must instead always point
            // to the canonical dashboard host.
            'login_url' => $this->branchDashboardLoginUrl(),
        ];
    }

    private function branchDashboardLoginUrl(): string
    {
        if (app()->environment(['local', 'testing'])) {
            return url('/dashboard/login');
        }

        return 'https://'.rtrim((string) config('app.product_admin_host'), '/').'/dashboard/login';
    }

    /** @param array<int, string>|null $requested */
    private function resolvedPermissions(string $role, ?array $requested): array
    {
        if ($role === 'owner') {
            return User::DASHBOARD_PERMISSIONS;
        }

        $permissions = $requested ?? [
            'overview', 'orders', 'merchants', 'couriers',
            'courier_locations', 'notifications',
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
