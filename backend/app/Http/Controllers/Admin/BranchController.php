<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Cashbox;
use App\Models\BranchMembership;
use App\Models\Order;
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
        $platformTenant = Tenant::platform();
        $branches = $this->networkBranches()
            ->with(['members' => fn ($members) => $members->select('users.id', 'users.name', 'users.username', 'users.role', 'users.status')])
            ->withCount([
                'users',
                'originOrders as outbound_orders_count',
                'destinationOrders as inbound_orders_count',
            ])
            ->orderBy('name_ar')
            ->get()
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name_ar' => $branch->name_ar,
                'name_en' => $branch->name_en,
                'name_ku' => $branch->name_ku,
                'city' => $branch->city,
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
                // Passwords are intentionally never returned after the one-time
                // creation flash.  The dashboard may still audit which access
                // accounts have been granted to this branch.
                'access_accounts' => $branch->members->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'role' => $user->pivot?->access_role === BranchMembership::OWNER ? 'owner' : 'branch_manager',
                    'status' => $user->status,
                ])->values(),
            ]);

        return Inertia::render('Admin/Branches', [
            'branches' => $branches,
            // A platform owner may be explicitly attached to more than one
            // branch. Only already-active dashboard accounts from the
            // platform tenant are exposed for that safe assignment.
            'accessUsers' => User::query()
                ->where('tenant_id', $platformTenant->id)
                ->where('status', 'active')
                ->whereIn('role', ['owner', 'branch_manager'])
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name', 'username', 'role'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'role' => $user->role,
                ])
                ->values(),
        ]);
    }

    public function store(Request $request)
    {
        $platformTenant = Tenant::platform();
        $branch = null;
        $credentials = null;

        \DB::transaction(function () use ($request, $platformTenant, &$branch, &$credentials): void {
            $branch = Branch::withoutGlobalScope(TenantScope::class)->create($this->validatedBranchData($request) + [
                'tenant_id' => $platformTenant->id,
                'is_platform_managed' => true,
                'is_active' => true,
            ]);

            if ($request->boolean('create_access_account')) {
                $credentials = $this->createBranchAccessAccount($request, $branch, $platformTenant);
            }
        });

        $this->record($request, $branch, 'branch.created', ['is_active' => true]);

        $response = back()->with('success', __('Branch created successfully.'));

        return $credentials ? $response->with('branch_credentials', $credentials) : $response;
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
        ]);

        return back()
            ->with('success', __('Branch dashboard account created successfully.'))
            ->with('branch_credentials', $credentials);
    }

    public function update(Request $request, int $branch)
    {
        $branch = $this->findNetworkBranch($branch);
        $before = $branch->only(['code', 'name_ar', 'name_en', 'name_ku', 'city', 'phone', 'address']);

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

        if ($branch) {
            $uniqueCode->ignore($branch->id);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', $uniqueCode],
            'name_ar' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'name_ku' => ['nullable', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        return $data;
    }

    /**
     * Create a least-privilege account whose only dashboard visibility is the
     * one branch membership below.  The plaintext password exists solely in
     * the immediate Inertia flash response; it is never stored in a setting,
     * activity payload, or API response.
     *
     * @return array{user_id:int,branch_id:int,branch_name:string,role:string,username:string,password:string,login_url:string}
     */
    private function createBranchAccessAccount(Request $request, Branch $branch, Tenant $platformTenant): array
    {
        foreach (['access_name', 'access_phone', 'access_username'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => trim((string) $request->input($field))]);
            }
        }

        $data = $request->validate([
            'access_name' => ['required', 'string', 'max:120'],
            'access_phone' => ['required', 'string', 'max:30', Rule::unique('users', 'phone')],
            'access_username' => ['nullable', 'string', 'alpha_dash', 'min:3', 'max:60', Rule::unique('users', 'username')],
            'access_password' => ['nullable', 'string', 'min:10', 'max:120'],
            'access_role' => ['required', Rule::in(['owner', 'branch_manager'])],
        ]);

        $username = filled($data['access_username'] ?? null)
            ? $data['access_username']
            : $this->nextBranchUsername($branch->code);
        $password = filled($data['access_password'] ?? null)
            ? $data['access_password']
            : $this->generatedPassword();
        $role = $data['access_role'];

        $user = User::create([
            'tenant_id' => $platformTenant->id,
            'branch_id' => $branch->id,
            'name' => $data['access_name'],
            'username' => $username,
            'phone' => $data['access_phone'],
            'password' => $password,
            'role' => $role,
            'status' => 'active',
            'locale' => 'ar',
            'theme' => 'light',
        ]);

        $branch->members()->attach($user->id, [
            'access_role' => $role === 'owner' ? BranchMembership::OWNER : BranchMembership::MANAGER,
        ]);

        return [
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'branch_name' => $branch->name_ar ?: $branch->code,
            'role' => $role,
            'username' => $username,
            'password' => $password,
            'login_url' => url('/dashboard/login'),
        ];
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

    private function generatedPassword(): string
    {
        // The markers guarantee an immediately usable strong password while
        // the random segment keeps it unguessable. It is shown exactly once.
        return 'Mn!'.Str::random(14).'7';
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
