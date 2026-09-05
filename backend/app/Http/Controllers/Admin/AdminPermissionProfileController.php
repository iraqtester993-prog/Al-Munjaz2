<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\DashboardPermissionProfile;
use App\Models\User;
use App\Services\BranchDashboardAuthorization;
use App\Services\BranchDashboardContext;
use App\Services\BranchDashboardScope;
use App\Services\DashboardBranchFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminPermissionProfileController extends Controller
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
            ->withCount('users')
            ->with(['users' => fn ($query) => $query
                ->where('role', 'admin')
                ->orderBy('name')
                ->select(['id', 'permission_profile_id', 'name', 'username', 'status', 'is_super_admin'])])
            ->orderBy('name')
            ->get()
            ->map(fn (DashboardPermissionProfile $profile) => $this->profileData($profile))
            ->values();

        $users = User::query()
            ->where('role', 'admin')
            ->with('permissionProfile:id,name')
            ->orderByDesc('is_super_admin')
            ->orderBy('name')
            ->get(['id', 'permission_profile_id', 'name', 'username', 'email', 'phone', 'status', 'is_super_admin', 'last_active_at'])
            ->map(fn (User $user) => $this->userData($user))
            ->values();

        return Inertia::render('Admin/Permissions', [
            'profiles' => $profiles,
            'modules' => DashboardPermissionProfile::catalog(),
            'users' => $users,
            'canManageProfiles' => true,
            'branchAudit' => false,
            'branchFilter' => $branchFilter->payload($request, $scope),
        ]);
    }

    public function store(Request $request)
    {
        if ($scope = $this->branchScope($request)) {
            return $this->branchStore($request, $scope);
        }

        $this->ensureSuperAdministrator($request);
        $this->ensureNotBranchAuditWrite($request);
        $data = $this->validatedProfileData($request);

        $profile = DashboardPermissionProfile::create($data + [
            'created_by' => $request->user()->id,
        ]);

        $this->record($request, 'dashboard.permission_profile.created', $profile, [
            'name' => $profile->name,
            'permissions' => $profile->permissions,
        ]);

        return back()->with('success', __('Permission profile created.'));
    }

    public function update(Request $request, DashboardPermissionProfile $permissionProfile)
    {
        if ($scope = $this->branchScope($request)) {
            return $this->branchUpdate($request, $scope, $permissionProfile);
        }

        $this->ensureSuperAdministrator($request);
        $this->ensureNotBranchAuditWrite($request);
        $data = $this->validatedProfileData($request, $permissionProfile);
        $before = $permissionProfile->permissions ?? [];

        $permissionProfile->update($data);

        $this->record($request, 'dashboard.permission_profile.updated', $permissionProfile, [
            'name' => $permissionProfile->name,
            'before_permissions' => $before,
            'permissions' => $permissionProfile->permissions,
        ]);

        return back()->with('success', __('Permission profile updated.'));
    }

    public function destroy(Request $request, DashboardPermissionProfile $permissionProfile)
    {
        if ($scope = $this->branchScope($request)) {
            return $this->branchDestroy($request, $scope, $permissionProfile);
        }

        $this->ensureSuperAdministrator($request);
        $this->ensureNotBranchAuditWrite($request);
        $snapshot = [
            'name' => $permissionProfile->name,
            'permissions' => $permissionProfile->permissions ?? [],
            'assigned_users' => $permissionProfile->users()->count(),
        ];

        DB::transaction(function () use ($permissionProfile, $request, $snapshot): void {
            // The database foreign key changes every assigned user's profile
            // to null.  Null intentionally means no dashboard access, so
            // deleting a profile can only revoke access, never broaden it.
            $permissionProfile->delete();
            $this->record($request, 'dashboard.permission_profile.deleted', $permissionProfile, $snapshot);
        });

        return back()->with('success', __('Permission profile deleted and assigned access revoked.'));
    }

    public function updateAssignment(Request $request, User $user)
    {
        if ($scope = $this->branchScope($request)) {
            return $this->branchUpdateAssignment($request, $scope, $user);
        }

        $this->ensureSuperAdministrator($request);
        $this->ensureNotBranchAuditWrite($request);
        abort_unless($user->isAdmin(), 404);

        // An existing super administrator may not be altered or downgraded
        // by this management screen.  The flag has no public mutation route.
        abort_if($user->isSuperAdmin(), 422, __('Super administrator access cannot be changed here.'));

        $data = $request->validate([
            'permission_profile_id' => ['nullable', 'integer', Rule::exists('dashboard_permission_profiles', 'id')],
        ]);

        $profile = isset($data['permission_profile_id'])
            ? DashboardPermissionProfile::findOrFail($data['permission_profile_id'])
            : null;
        $previousProfileId = $user->permission_profile_id;

        $user->update(['permission_profile_id' => $profile?->id]);

        $this->record($request, 'dashboard.permission_profile.assigned', $user, [
            'previous_profile_id' => $previousProfileId,
            'permission_profile_id' => $profile?->id,
            'permission_profile_name' => $profile?->name,
        ]);

        return back()->with('success', $profile
            ? __('Permission profile assigned.')
            : __('Permission profile removed and dashboard access revoked.'));
    }

    /** @return array{name: string, permissions: array<string, array<int, string>>} */
    private function validatedProfileData(Request $request, ?DashboardPermissionProfile $profile = null, bool $local = false): array
    {
        $nameRule = Rule::unique('dashboard_permission_profiles', 'name');
        if ($profile) {
            $nameRule = $nameRule->ignore($profile->id);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120', $nameRule],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['array'],
        ]);

        return [
            'name' => trim($data['name']),
            'permissions' => $local
                ? app(BranchDashboardAuthorization::class)->normalizeLocalProfilePermissions($data['permissions'] ?? [])
                : DashboardPermissionProfile::normalizePermissions($data['permissions'] ?? []),
        ];
    }

    private function branchScope(Request $request): ?BranchDashboardScope
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $scope = app(BranchDashboardContext::class)->fromRequest($request);
        if (! $scope->requiresBranchScope()) {
            return null;
        }

        abort_unless(
            $scope->hasBranchScope()
                && app(BranchDashboardAuthorization::class)->isBranchManager($user),
            403,
        );

        return $scope;
    }

    private function branchIndex(Request $request, BranchDashboardScope $scope)
    {
        $branchId = $scope->branchId();
        $profiles = DashboardPermissionProfile::query()
            ->where('branch_id', $branchId)
            ->withCount(['users as users_count' => fn ($users) => $users
                ->where('role', 'branch_manager')
                ->where('branch_id', $branchId)])
            ->with(['users' => fn ($users) => $users
                ->where('role', 'branch_manager')
                ->where('branch_id', $branchId)
                ->orderBy('name')
                ->select(['id', 'permission_profile_id', 'name', 'username', 'status', 'is_super_admin'])])
            ->orderBy('name')
            ->get()
            ->map(fn (DashboardPermissionProfile $profile) => $this->profileData($profile))
            ->values();

        $users = $scope->restrictUsers(User::query())
            ->where('role', 'branch_manager')
            ->with('permissionProfile:id,branch_id,name')
            ->orderBy('name')
            ->get(['id', 'branch_id', 'permission_profile_id', 'name', 'username', 'email', 'phone', 'status', 'last_active_at'])
            ->map(fn (User $user) => $this->branchUserData($user))
            ->values();

        return Inertia::render('Admin/Permissions', [
            'profiles' => $profiles,
            'modules' => app(BranchDashboardAuthorization::class)->localProfileCatalog(),
            'users' => $users,
            'canManageProfiles' => true,
            'branchAudit' => false,
            'branchFilter' => app(DashboardBranchFilter::class)->payload($request, $scope),
        ]);
    }

    /**
     * This is a historical review of a selected branch's local profiles and
     * staff. It intentionally does not reuse the global profile editor's
     * mutation paths: branch managers retain the only writable local scope.
     */
    private function branchAuditIndex(
        Request $request,
        BranchDashboardScope $scope,
        DashboardBranchFilter $branchFilter,
        int $branchId,
    ) {
        $branchFilter->platformBranches()
            ->whereKey($branchId)
            ->firstOrFail();

        $profiles = DashboardPermissionProfile::query()
            ->where('branch_id', $branchId)
            ->withCount(['users as users_count' => fn ($users) => $users
                ->where('role', 'branch_manager')
                ->where('branch_id', $branchId)])
            ->with(['users' => fn ($users) => $users
                ->where('role', 'branch_manager')
                ->where('branch_id', $branchId)
                ->orderBy('name')
                ->select(['id', 'permission_profile_id', 'name', 'username', 'status', 'is_super_admin'])])
            ->orderBy('name')
            ->get()
            ->map(fn (DashboardPermissionProfile $profile) => $this->profileData($profile))
            ->values();

        $users = User::query()
            ->where('role', 'branch_manager')
            ->where('branch_id', $branchId)
            ->with(['permissionProfile' => fn ($profiles) => $profiles
                ->where('branch_id', $branchId)
                ->select(['id', 'branch_id', 'name'])])
            ->orderBy('name')
            ->get(['id', 'branch_id', 'permission_profile_id', 'name', 'username', 'email', 'phone', 'status', 'last_active_at'])
            ->map(fn (User $user) => $this->branchUserData($user))
            ->values();

        return Inertia::render('Admin/Permissions', [
            'profiles' => $profiles,
            'modules' => app(BranchDashboardAuthorization::class)->localProfileCatalog(),
            'users' => $users,
            'canManageProfiles' => false,
            'branchAudit' => true,
            'branchFilter' => $branchFilter->payload($request, $scope),
        ]);
    }

    private function branchStore(Request $request, BranchDashboardScope $scope)
    {
        $data = $this->validatedProfileData($request, null, true);
        $profile = DashboardPermissionProfile::create($data + [
            'branch_id' => $scope->branchId(),
            'created_by' => $request->user()->id,
        ]);

        $this->record($request, 'branch.permission_profile.created', $profile, [
            'branch_id' => $scope->branchId(),
            'name' => $profile->name,
            'permissions' => $profile->permissions,
        ]);

        return back()->with('success', __('Permission profile created.'));
    }

    private function branchUpdate(Request $request, BranchDashboardScope $scope, DashboardPermissionProfile $boundProfile)
    {
        $profile = $this->branchProfile($scope, $boundProfile);
        $data = $this->validatedProfileData($request, $profile, true);
        $before = $profile->permissions ?? [];
        $profile->update($data);

        $this->record($request, 'branch.permission_profile.updated', $profile, [
            'branch_id' => $scope->branchId(),
            'name' => $profile->name,
            'before_permissions' => $before,
            'permissions' => $profile->permissions,
        ]);

        return back()->with('success', __('Permission profile updated.'));
    }

    private function branchDestroy(Request $request, BranchDashboardScope $scope, DashboardPermissionProfile $boundProfile)
    {
        $profile = $this->branchProfile($scope, $boundProfile);
        $assignedEmployees = $profile->users()
            ->where('role', 'branch_manager')
            ->where('branch_id', $scope->branchId())
            ->exists();

        // A branch manager without a profile is the protected principal
        // manager. Deleting an in-use local profile would therefore turn its
        // employees into principal managers through the FK null-on-delete.
        abort_if(
            $assignedEmployees,
            422,
            'لا يمكن حذف ملف صلاحيات مرتبط بموظفين. انقل الموظفين إلى ملف آخر أولاً.'
        );

        $snapshot = [
            'branch_id' => $scope->branchId(),
            'name' => $profile->name,
            'permissions' => $profile->permissions ?? [],
            'assigned_users' => $profile->users()->count(),
        ];

        DB::transaction(function () use ($profile, $request, $snapshot): void {
            $profile->delete();
            $this->record($request, 'branch.permission_profile.deleted', $profile, $snapshot);
        });

        return back()->with('success', __('Permission profile deleted and assigned access revoked.'));
    }

    private function branchUpdateAssignment(Request $request, BranchDashboardScope $scope, User $boundUser)
    {
        $user = $scope->restrictUsers(User::query())
            ->whereKey($boundUser->id)
            ->where('role', 'branch_manager')
            ->firstOrFail();
        // The primary branch manager has no mutable profile. Giving them one
        // would silently convert the account into a restricted employee.
        abort_if($user->permission_profile_id === null, 422, 'لا يمكن تغيير صلاحيات مدير الفرع الرئيسي من هذه الشاشة.');

        $data = $request->validate([
            'permission_profile_id' => ['required', 'integer'],
        ]);
        $profile = DashboardPermissionProfile::query()
            ->where('branch_id', $scope->branchId())
            ->whereKey($data['permission_profile_id'])
            ->firstOrFail();
        $previousProfileId = $user->permission_profile_id;
        $user->update(['permission_profile_id' => $profile->id]);

        $this->record($request, 'branch.permission_profile.assigned', $user, [
            'branch_id' => $scope->branchId(),
            'previous_profile_id' => $previousProfileId,
            'permission_profile_id' => $profile->id,
            'permission_profile_name' => $profile->name,
        ]);

        return back()->with('success', __('Permission profile assigned.'));
    }

    private function branchProfile(BranchDashboardScope $scope, DashboardPermissionProfile $boundProfile): DashboardPermissionProfile
    {
        return DashboardPermissionProfile::query()
            ->where('branch_id', $scope->branchId())
            ->whereKey($boundProfile->id)
            ->firstOrFail();
    }

    private function ensureSuperAdministrator(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
    }

    /**
     * Selecting a branch is an audit view for the platform owner. The UI
     * removes mutation controls, and this check prevents a stale form or a
     * hand-crafted branch_id request from writing a global profile instead.
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
            'Branch-filtered permission data is read-only.',
        );
    }

    /** @return array<string, mixed> */
    private function profileData(DashboardPermissionProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'name' => $profile->name,
            'permissions' => $profile->permissions ?? [],
            'users_count' => (int) $profile->users_count,
            'users' => $profile->users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'status' => $user->status,
                'is_super_admin' => (bool) $user->is_super_admin,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
            'is_super_admin' => (bool) $user->is_super_admin,
            'permission_profile_id' => $user->permission_profile_id,
            'permission_profile' => $user->permissionProfile ? [
                'id' => $user->permissionProfile->id,
                'name' => $user->permissionProfile->name,
            ] : null,
            'last_active_at' => $user->last_active_at?->toDateTimeString(),
        ];
    }

    /** @return array<string, mixed> */
    private function branchUserData(User $user): array
    {
        $isPrincipalManager = $user->permission_profile_id === null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
            'is_super_admin' => false,
            'is_protected_manager' => $isPrincipalManager,
            'permission_profile_id' => $user->permission_profile_id,
            'permission_profile' => $user->permissionProfile ? [
                'id' => $user->permissionProfile->id,
                'name' => $user->permissionProfile->name,
            ] : null,
            'last_active_at' => $user->last_active_at?->toDateTimeString(),
        ];
    }

    private function record(Request $request, string $action, mixed $subject, array $data = []): void
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
