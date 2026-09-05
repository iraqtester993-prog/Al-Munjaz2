<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\DashboardInvitation;
use App\Models\DashboardPermissionProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchDashboardAuthorization;
use App\Services\BranchDashboardContext;
use App\Services\BranchDashboardScope;
use App\Services\DashboardBranchFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Manages platform staff only.  Merchant, courier, and branch accounts use
 * their dedicated operational management flows and can never be reached
 * through these routes merely by changing a URL parameter.
 */
class AdminEmployeeController extends Controller
{
    public function index(Request $request)
    {
        if ($scope = $this->branchScope($request)) {
            return $this->branchIndex($request, $scope);
        }

        $this->ensureSuperAdministrator($request);
        $scope = app(BranchDashboardContext::class)->fromRequest($request);
        $branchFilter = app(DashboardBranchFilter::class);
        $selectedBranchId = $branchFilter->selectedBranchId($request, $scope);

        if ($selectedBranchId !== null) {
            return $this->branchAuditIndex($request, $scope, $branchFilter, $selectedBranchId);
        }

        $profiles = DashboardPermissionProfile::query()
            ->withCount(['users as employees_count' => fn ($query) => $query->where('role', 'admin')])
            ->orderBy('name')
            ->get(['id', 'name', 'permissions'])
            ->map(fn (DashboardPermissionProfile $profile) => $this->profileData($profile))
            ->values();

        $employees = User::query()
            ->where('role', 'admin')
            ->with('permissionProfile:id,name,permissions')
            ->orderByDesc('is_super_admin')
            ->orderBy('status')
            ->orderBy('name')
            ->get([
                'id', 'tenant_id', 'permission_profile_id', 'name', 'email',
                'role', 'status', 'is_super_admin', 'last_active_at', 'created_at',
            ]);

        $superAdministratorCount = $employees->where('is_super_admin', true)->count();
        $activeSuperAdministratorCount = $employees
            ->where('is_super_admin', true)
            ->where('status', 'active')
            ->count();

        return Inertia::render('Admin/Employees', [
            'employees' => $employees
                ->map(fn (User $employee) => $this->employeeData(
                    $employee,
                    $request->user(),
                    $superAdministratorCount,
                    $activeSuperAdministratorCount,
                ))
                ->values(),
            // Keeping profile permissions in this payload lets the staff
            // screen explain the assigned scope without duplicating the
            // server-owned permission matrix in Vue.
            'profiles' => $profiles,
            'permissionModules' => DashboardPermissionProfile::catalog(),
            'invitations' => DashboardInvitation::query()
                ->with(['inviter:id,name', 'acceptedBy:id,name', 'permissionProfile:id,name,permissions'])
                ->latest('id')
                ->limit(100)
                ->get()
                ->map(fn (DashboardInvitation $invitation) => $this->invitationData($invitation))
                ->values(),
            'canManageEmployees' => true,
            'branchAudit' => false,
            'branchFilter' => $branchFilter->payload($request, $scope),
        ]);
    }

    /**
     * Create a dashboard-only system employee immediately. The administrator
     * selects a server-owned permission profile up front; the employee can
     * then sign in at the usual dashboard URL with their email and password.
     *
     * A username remains an internal compatibility identifier for the legacy
     * users table. System employees do not have to know or use it because the
     * dashboard login accepts their email address.
     */
    public function store(Request $request)
    {
        if ($scope = $this->branchScope($request)) {
            return $this->branchStore($request, $scope);
        }

        $this->ensureSuperAdministrator($request);
        $this->ensureNotBranchAuditWrite($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            // Every directly created employee must start with an explicit
            // scope. A role alone never grants dashboard access.
            'permission_profile_id' => ['required', 'integer', Rule::exists('dashboard_permission_profiles', 'id')],
        ]);

        $email = Str::lower(trim($data['email']));

        abort_if(
            User::query()->withoutGlobalScopes()->withTrashed()->where('email', $email)->exists(),
            422,
            __('A user already uses this email.'),
        );

        DB::transaction(function () use ($data, $email, $request): User {
            $employee = User::create([
                'tenant_id' => Tenant::platform()->id,
                'name' => trim($data['name']),
                'username' => $this->nextSystemUsername(),
                'email' => $email,
                // A dashboard-only account signs in with its email and has
                // no operational phone number. The users column is nullable
                // for this purpose; merchant/courier registration is intact.
                'phone' => null,
                'password' => $data['password'],
                'role' => 'admin',
                'status' => 'active',
                'permission_profile_id' => $data['permission_profile_id'],
            ]);

            $this->record($request, 'dashboard.employee.created', $employee, [
                'name' => $employee->name,
                'email' => $employee->email,
                'permission_profile_id' => $employee->permission_profile_id,
            ]);

            return $employee;
        });

        return back()->with('success', __('System employee account created.'));
    }

    /**
     * Provisioning remains invitation-only: the recipient selects their own
     * username, phone, and password through the existing one-time acceptance
     * flow.  This avoids an administrator handling another employee's secret.
     */
    public function invite(Request $request)
    {
        if ($scope = $this->branchScope($request)) {
            return back()->withErrors([
                'invite' => 'أنشئ موظف الفرع مباشرة مع كلمة المرور وملف الصلاحيات.',
            ]);
        }

        $this->ensureSuperAdministrator($request);
        $this->ensureNotBranchAuditWrite($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            // New staff must have an explicit, named scope. A null profile is
            // safe but not useful, so the employee screen does not mint it.
            'permission_profile_id' => ['required', 'integer', Rule::exists('dashboard_permission_profiles', 'id')],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $email = Str::lower(trim($data['email']));

        abort_if(
            User::query()->withTrashed()->where('email', $email)->exists(),
            422,
            __('A user already uses this email.'),
        );
        abort_if(
            DashboardInvitation::query()
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->exists(),
            422,
            __('A valid invitation already exists for this email.'),
        );

        $token = Str::random(64);
        $invitation = DashboardInvitation::create([
            'invited_by' => $request->user()->id,
            'name' => trim($data['name']),
            'email' => $email,
            'role' => DashboardInvitation::ROLE,
            'permission_profile_id' => $data['permission_profile_id'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays((int) ($data['expires_in_days'] ?? 7)),
        ]);

        $this->record($request, 'dashboard.employee.invited', $invitation, [
            'email' => $invitation->email,
            'permission_profile_id' => $invitation->permission_profile_id,
        ]);

        $path = route('admin.invitations.accept', ['token' => $token], false);
        $inviteUrl = rtrim($request->getSchemeAndHttpHost(), '/').$path;

        return back()
            ->with('success', __('Employee invitation created. Copy the secure link before leaving this screen.'))
            ->with('invite_link', $inviteUrl);
    }

    /**
     * Editing staff deliberately excludes role, status, and the super-admin
     * flag. A password may be reset without ever returning or recording it.
     */
    public function update(Request $request, User $user)
    {
        if ($scope = $this->branchScope($request)) {
            return $this->branchUpdate($request, $scope, $user);
        }

        $this->ensureSuperAdministrator($request);
        $this->ensureNotBranchAuditWrite($request);
        $this->ensureEmployee($user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'permission_profile_id' => [
                $user->isSuperAdmin() ? 'nullable' : 'required',
                'integer',
                Rule::exists('dashboard_permission_profiles', 'id'),
            ],
            // Retain the legacy identifiers if an older client still sends
            // them, but the System Employees UI no longer requires either.
            'username' => ['sometimes', 'nullable', 'string', 'min:3', 'max:60', Rule::unique('users', 'username')->ignore($user->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($user->id)],
        ]);

        $profileChanged = array_key_exists('permission_profile_id', $data)
            && (int) ($data['permission_profile_id'] ?? 0) !== (int) ($user->permission_profile_id ?? 0);

        // A super administrator is intentionally not assignable to a named
        // profile. There is also no input that can turn any employee into a
        // super administrator, so a delegated account cannot self-escalate.
        abort_if(
            $user->isSuperAdmin() && $profileChanged,
            422,
            __('Super administrator access cannot be changed here.'),
        );

        $before = [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'permission_profile_id' => $user->permission_profile_id,
        ];

        $attributes = [
            'name' => trim($data['name']),
            'email' => Str::lower(trim($data['email'])),
        ];

        if (array_key_exists('username', $data) && filled($data['username'])) {
            $attributes['username'] = trim($data['username']);
        }
        if (array_key_exists('phone', $data)) {
            $attributes['phone'] = filled($data['phone']) ? trim($data['phone']) : null;
        }
        if (! $user->isSuperAdmin() && array_key_exists('permission_profile_id', $data)) {
            $attributes['permission_profile_id'] = $data['permission_profile_id'];
        }

        $passwordChanged = filled($data['password'] ?? null);
        if ($passwordChanged) {
            $attributes['password'] = $data['password'];
        }

        $user->update($attributes);
        if ($passwordChanged) {
            $user->tokens()->delete();
        }

        $this->record($request, 'dashboard.employee.updated', $user, [
            'before' => $before,
            'fields' => array_values(array_diff(array_keys($attributes), ['password'])),
            'password_changed' => $passwordChanged,
            'permission_profile_id' => $user->permission_profile_id,
        ]);

        return back()->with('success', __('Employee account updated.'));
    }

    public function status(Request $request, User $user)
    {
        if ($scope = $this->branchScope($request)) {
            return $this->branchStatus($request, $scope, $user);
        }

        $this->ensureSuperAdministrator($request);
        $this->ensureNotBranchAuditWrite($request);
        $this->ensureEmployee($user);

        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ]);

        abort_if($user->is($request->user()), 422, __('You cannot change the status of the account you are using.'));

        DB::transaction(function () use ($data, $request, $user): void {
            $employee = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->ensureEmployee($employee);

            if ($employee->isSuperAdmin() && $employee->isActiveUser() && $data['status'] !== 'active') {
                $activeSuperAdministratorCount = $this->activeSuperAdministratorQuery()
                    ->lockForUpdate()
                    ->count();

                abort_if(
                    $activeSuperAdministratorCount <= 1,
                    422,
                    __('At least one active super administrator must remain.'),
                );
            }

            $previousStatus = $employee->status;
            $employee->update(['status' => $data['status']]);

            // A suspended employee must re-authenticate after reactivation;
            // an old API token cannot remain a valid administrative session.
            if ($data['status'] === 'suspended') {
                $employee->tokens()->delete();
            }

            $this->record($request, 'dashboard.employee.status_updated', $employee, [
                'previous_status' => $previousStatus,
                'status' => $data['status'],
            ]);
        });

        return back()->with('success', __('Employee account status updated.'));
    }

    /**
     * Deletion is recoverable (soft delete) and revokes issued API tokens.
     * The last super administrator and the current account are protected even
     * if a client bypasses the disabled buttons in the dashboard UI.
     */
    public function destroy(Request $request, User $user)
    {
        if ($scope = $this->branchScope($request)) {
            return $this->branchDestroy($request, $scope, $user);
        }

        $this->ensureSuperAdministrator($request);
        $this->ensureNotBranchAuditWrite($request);
        $this->ensureEmployee($user);
        abort_if($user->is($request->user()), 422, __('You cannot delete the account you are using.'));

        DB::transaction(function () use ($request, $user): void {
            $employee = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->ensureEmployee($employee);

            if ($employee->isSuperAdmin()) {
                $superAdministratorCount = $this->superAdministratorQuery()
                    ->lockForUpdate()
                    ->count();
                abort_if(
                    $superAdministratorCount <= 1,
                    422,
                    __('The last super administrator cannot be deleted.'),
                );

                if ($employee->isActiveUser()) {
                    $activeSuperAdministratorCount = $this->activeSuperAdministratorQuery()
                        ->lockForUpdate()
                        ->count();
                    abort_if(
                        $activeSuperAdministratorCount <= 1,
                        422,
                        __('At least one active super administrator must remain.'),
                    );
                }
            }

            $snapshot = [
                'name' => $employee->name,
                'username' => $employee->username,
                'email' => $employee->email,
                'is_super_admin' => $employee->isSuperAdmin(),
                'permission_profile_id' => $employee->permission_profile_id,
            ];

            $employee->forceFill(['status' => 'suspended', 'is_online' => false])->save();
            $employee->tokens()->delete();
            $employee->delete();

            $this->record($request, 'dashboard.employee.deleted', $employee, $snapshot);
        });

        return back()->with('success', __('Employee account deleted safely.'));
    }

    private function branchScope(Request $request): ?BranchDashboardScope
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(403);
        }

        $scope = app(BranchDashboardContext::class)->fromRequest($request);
        if (! $scope->requiresBranchScope()) {
            return null;
        }

        abort_unless(
            $scope->hasBranchScope()
                && app(BranchDashboardAuthorization::class)->isBranchManager($actor),
            403,
        );

        return $scope;
    }

    private function branchIndex(Request $request, BranchDashboardScope $scope)
    {
        $branchId = $scope->branchId();
        $authorization = app(BranchDashboardAuthorization::class);
        $profiles = DashboardPermissionProfile::query()
            ->where('branch_id', $branchId)
            ->withCount(['users as employees_count' => fn ($users) => $users
                ->where('role', 'branch_manager')
                ->where('branch_id', $branchId)])
            ->orderBy('name')
            ->get(['id', 'branch_id', 'name', 'permissions'])
            ->map(fn (DashboardPermissionProfile $profile) => $this->profileData($profile))
            ->values();

        $employees = $scope->restrictUsers(User::query())
            ->where('role', 'branch_manager')
            ->with('permissionProfile:id,branch_id,name,permissions')
            ->orderBy('status')
            ->orderBy('name')
            ->get([
                'id', 'tenant_id', 'branch_id', 'permission_profile_id', 'name', 'email',
                'role', 'status', 'is_super_admin', 'last_active_at', 'created_at',
            ]);

        return Inertia::render('Admin/Employees', [
            'employees' => $employees
                ->map(fn (User $employee) => $this->branchEmployeeData($employee, $request->user(), $scope, $authorization))
                ->values(),
            'profiles' => $profiles,
            'permissionModules' => $authorization->localProfileCatalog(),
            'invitations' => [],
            'canManageEmployees' => true,
            'branchAudit' => false,
            'branchFilter' => app(DashboardBranchFilter::class)->payload($request, $scope),
        ]);
    }

    /**
     * The platform owner may inspect a branch's local access records, even
     * after that branch has been disabled. This is deliberately an audit
     * surface: writes stay on the branch manager's server-owned scope, so a
     * selected branch can never turn a platform-staff mutation into a
     * cross-scope write by accident.
     */
    private function branchAuditIndex(
        Request $request,
        BranchDashboardScope $scope,
        DashboardBranchFilter $branchFilter,
        int $branchId,
    ) {
        /** @var Branch $branch */
        $branch = $branchFilter->platformBranches()
            ->whereKey($branchId)
            ->firstOrFail();
        $authorization = app(BranchDashboardAuthorization::class);

        $profiles = DashboardPermissionProfile::query()
            ->where('branch_id', $branchId)
            ->withCount(['users as employees_count' => fn ($users) => $users
                ->where('role', 'branch_manager')
                ->where('branch_id', $branchId)])
            ->orderBy('name')
            ->get(['id', 'branch_id', 'name', 'permissions'])
            ->map(fn (DashboardPermissionProfile $profile) => $this->profileData($profile))
            ->values();

        $employees = User::query()
            ->where('role', 'branch_manager')
            ->where('branch_id', $branchId)
            ->with(['permissionProfile' => fn ($profiles) => $profiles
                ->where('branch_id', $branchId)
                ->select(['id', 'branch_id', 'name', 'permissions'])])
            ->orderBy('status')
            ->orderBy('name')
            ->get([
                'id', 'tenant_id', 'branch_id', 'permission_profile_id', 'name', 'email',
                'role', 'status', 'is_super_admin', 'last_active_at', 'created_at',
            ]);

        return Inertia::render('Admin/Employees', [
            'employees' => $employees
                ->map(fn (User $employee) => $this->branchEmployeeData(
                    $employee,
                    $request->user(),
                    BranchDashboardScope::forBranch($employee, $branch),
                    $authorization,
                ))
                ->values(),
            'profiles' => $profiles,
            'permissionModules' => $authorization->localProfileCatalog(),
            // Invitations do not belong to a branch and are therefore never
            // mixed into this scoped historical review.
            'invitations' => [],
            'canManageEmployees' => false,
            'branchAudit' => true,
            'branchFilter' => $branchFilter->payload($request, $scope),
        ]);
    }

    private function branchStore(Request $request, BranchDashboardScope $scope)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'permission_profile_id' => ['required', 'integer'],
        ]);
        $profile = $this->branchProfile($scope, (int) $data['permission_profile_id']);
        $email = Str::lower(trim($data['email']));

        abort_if(
            User::query()->withoutGlobalScopes()->withTrashed()->where('email', $email)->exists(),
            422,
            __('A user already uses this email.'),
        );

        DB::transaction(function () use ($data, $email, $request, $scope, $profile): void {
            $employee = User::create([
                'tenant_id' => Tenant::platform()->id,
                'branch_id' => $scope->branchId(),
                'name' => trim($data['name']),
                'username' => $this->nextSystemUsername(),
                'email' => $email,
                'phone' => null,
                'password' => $data['password'],
                'role' => 'branch_manager',
                'status' => 'active',
                'permission_profile_id' => $profile->id,
            ]);
            app(BranchDashboardContext::class)->assignPrimaryMembership($employee, $scope->branch());

            $this->record($request, 'branch.employee.created', $employee, [
                'branch_id' => $scope->branchId(),
                'name' => $employee->name,
                'email' => $employee->email,
                'permission_profile_id' => $employee->permission_profile_id,
            ]);
        });

        return back()->with('success', __('System employee account created.'));
    }

    private function branchUpdate(Request $request, BranchDashboardScope $scope, User $boundUser)
    {
        $employee = $this->branchMutableEmployee($scope, $boundUser);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($employee->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'permission_profile_id' => ['required', 'integer'],
        ]);
        $profile = $this->branchProfile($scope, (int) $data['permission_profile_id']);
        $before = [
            'name' => $employee->name,
            'email' => $employee->email,
            'permission_profile_id' => $employee->permission_profile_id,
        ];
        $attributes = [
            'name' => trim($data['name']),
            'email' => Str::lower(trim($data['email'])),
            'permission_profile_id' => $profile->id,
        ];
        $passwordChanged = filled($data['password'] ?? null);
        if ($passwordChanged) {
            $attributes['password'] = $data['password'];
        }

        $employee->update($attributes);
        if ($passwordChanged) {
            $employee->tokens()->delete();
        }

        $this->record($request, 'branch.employee.updated', $employee, [
            'branch_id' => $scope->branchId(),
            'before' => $before,
            'fields' => array_values(array_diff(array_keys($attributes), ['password'])),
            'password_changed' => $passwordChanged,
            'permission_profile_id' => $employee->permission_profile_id,
        ]);

        return back()->with('success', __('Employee account updated.'));
    }

    private function branchStatus(Request $request, BranchDashboardScope $scope, User $boundUser)
    {
        $employee = $this->branchMutableEmployee($scope, $boundUser);
        abort_if($employee->is($request->user()), 422, __('You cannot change the status of the account you are using.'));
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])]]);

        DB::transaction(function () use ($data, $request, $scope, $employee): void {
            $locked = $scope->restrictUsers(User::query())
                ->whereKey($employee->id)
                ->where('role', 'branch_manager')
                ->lockForUpdate()
                ->firstOrFail();
            abort_if($locked->permission_profile_id === null, 422, 'لا يمكن تعطيل مدير الفرع الرئيسي.');

            $previousStatus = $locked->status;
            $locked->update(['status' => $data['status']]);
            if ($data['status'] === 'suspended') {
                $locked->tokens()->delete();
            }

            $this->record($request, 'branch.employee.status_updated', $locked, [
                'branch_id' => $scope->branchId(),
                'previous_status' => $previousStatus,
                'status' => $data['status'],
            ]);
        });

        return back()->with('success', __('Employee account status updated.'));
    }

    private function branchDestroy(Request $request, BranchDashboardScope $scope, User $boundUser)
    {
        $employee = $this->branchMutableEmployee($scope, $boundUser);
        abort_if($employee->is($request->user()), 422, __('You cannot delete the account you are using.'));

        DB::transaction(function () use ($request, $scope, $employee): void {
            $locked = $scope->restrictUsers(User::query())
                ->whereKey($employee->id)
                ->where('role', 'branch_manager')
                ->lockForUpdate()
                ->firstOrFail();
            abort_if($locked->permission_profile_id === null, 422, 'لا يمكن حذف مدير الفرع الرئيسي.');

            $snapshot = [
                'branch_id' => $scope->branchId(),
                'name' => $locked->name,
                'email' => $locked->email,
                'permission_profile_id' => $locked->permission_profile_id,
            ];
            $locked->forceFill(['status' => 'suspended', 'is_online' => false])->save();
            $locked->tokens()->delete();
            $locked->delete();
            $this->record($request, 'branch.employee.deleted', $locked, $snapshot);
        });

        return back()->with('success', __('Employee account deleted safely.'));
    }

    private function branchProfile(BranchDashboardScope $scope, int $profileId): DashboardPermissionProfile
    {
        return DashboardPermissionProfile::query()
            ->where('branch_id', $scope->branchId())
            ->whereKey($profileId)
            ->firstOrFail();
    }

    private function branchMutableEmployee(BranchDashboardScope $scope, User $boundUser): User
    {
        $employee = $scope->restrictUsers(User::query())
            ->whereKey($boundUser->id)
            ->where('role', 'branch_manager')
            ->firstOrFail();
        abort_if($employee->permission_profile_id === null, 422, 'لا يمكن تعديل مدير الفرع الرئيسي من شاشة الموظفين.');

        return $employee;
    }

    private function ensureSuperAdministrator(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
    }

    /**
     * A super-admin page filtered to one branch is intentionally read-only.
     * The browser also hides all controls in that mode, while this check
     * protects a stale modal or hand-crafted request that keeps branch_id.
     */
    private function ensureNotBranchAuditWrite(Request $request): void
    {
        $scope = app(BranchDashboardContext::class)->fromRequest($request);

        if (! $scope->isSuperAdmin()) {
            return;
        }

        abort_if(
            app(DashboardBranchFilter::class)->selectedBranchId($request, $scope) !== null,
            403,
            'Branch-filtered employee data is read-only.',
        );
    }

    private function ensureEmployee(User $user): void
    {
        abort_unless($user->isAdmin(), 404);
    }

    /** @return Builder<User> */
    private function superAdministratorQuery()
    {
        return User::query()
            ->where('role', 'admin')
            ->where('is_super_admin', true);
    }

    /** @return Builder<User> */
    private function activeSuperAdministratorQuery()
    {
        return $this->superAdministratorQuery()->where('status', 'active');
    }

    private function nextSystemUsername(): string
    {
        do {
            $username = 'system-'.Str::lower(Str::random(22));
        } while (User::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('username', $username)
            ->exists());

        return $username;
    }

    /** @return array<string, mixed> */
    private function employeeData(
        User $employee,
        User $actor,
        int $superAdministratorCount,
        int $activeSuperAdministratorCount,
    ): array {
        $isCurrentUser = $employee->is($actor);
        $isSuperAdministrator = $employee->isSuperAdmin();
        $canRemoveSuperAdministrator = ! $isSuperAdministrator
            || ($superAdministratorCount > 1
                && (! $employee->isActiveUser() || $activeSuperAdministratorCount > 1));
        $canSuspendSuperAdministrator = ! $isSuperAdministrator
            || ! $employee->isActiveUser()
            || $activeSuperAdministratorCount > 1;

        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'role' => $employee->role,
            'status' => $employee->status,
            'is_super_admin' => $isSuperAdministrator,
            'permission_profile_id' => $employee->permission_profile_id,
            'permission_profile' => $employee->permissionProfile ? $this->profileData($employee->permissionProfile) : null,
            'effective_permissions' => $isSuperAdministrator
                ? $this->allPermissions()
                : ($employee->permissionProfile?->permissions ?? []),
            'last_active_at' => $employee->last_active_at?->toDateTimeString(),
            'created_at' => $employee->created_at?->toDateTimeString(),
            'is_current_user' => $isCurrentUser,
            // These flags are only UI affordances. The controller enforces
            // the same restrictions for a crafted request.
            'can_change_status' => ! $isCurrentUser && $canSuspendSuperAdministrator,
            'can_delete' => ! $isCurrentUser && $canRemoveSuperAdministrator,
        ];
    }

    /** @return array<string, mixed> */
    private function branchEmployeeData(
        User $employee,
        User $actor,
        BranchDashboardScope $scope,
        BranchDashboardAuthorization $authorization,
    ): array {
        $isPrincipalManager = $authorization->isBranchManager($employee);
        $isCurrentUser = $employee->is($actor);

        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'role' => $employee->role,
            'status' => $employee->status,
            'is_super_admin' => false,
            'is_protected_manager' => $isPrincipalManager,
            'is_branch_manager' => $isPrincipalManager,
            'permission_profile_id' => $employee->permission_profile_id,
            'permission_profile' => $employee->permissionProfile ? $this->profileData($employee->permissionProfile) : null,
            'effective_permissions' => $authorization->effectivePermissions($employee, $scope),
            'last_active_at' => $employee->last_active_at?->toDateTimeString(),
            'created_at' => $employee->created_at?->toDateTimeString(),
            'is_current_user' => $isCurrentUser,
            'can_edit' => ! $isPrincipalManager,
            'can_change_status' => ! $isCurrentUser && ! $isPrincipalManager,
            'can_delete' => ! $isCurrentUser && ! $isPrincipalManager,
        ];
    }

    /** @return array<string, mixed> */
    private function profileData(DashboardPermissionProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'name' => $profile->name,
            'permissions' => $profile->permissions ?? [],
            'employees_count' => (int) ($profile->employees_count ?? 0),
        ];
    }

    /** @return array<string, array<int, string>> */
    private function allPermissions(): array
    {
        return collect(DashboardPermissionProfile::MODULES)
            ->map(fn (array $module) => $module['actions'])
            ->all();
    }

    /** @return array<string, mixed> */
    private function invitationData(DashboardInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'name' => $invitation->name,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'expires_at' => $invitation->expires_at?->toDateTimeString(),
            'accepted_at' => $invitation->accepted_at?->toDateTimeString(),
            'invited_by' => $invitation->inviter?->name,
            'accepted_by' => $invitation->acceptedBy?->name,
            'permission_profile_id' => $invitation->permission_profile_id,
            'permission_profile' => $invitation->permissionProfile ? $this->profileData($invitation->permissionProfile) : null,
            'state' => $invitation->accepted_at
                ? 'accepted'
                : ($invitation->expires_at->isPast() ? 'expired' : 'pending'),
        ];
    }

    private function record(Request $request, string $action, object $subject, array $data = []): void
    {
        ActivityLog::create([
            'tenant_id' => $subject instanceof User ? $subject->tenant_id : null,
            'user_id' => $request->user()?->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->id,
            'data' => $data,
            'ip' => $request->ip(),
        ]);
    }
}
