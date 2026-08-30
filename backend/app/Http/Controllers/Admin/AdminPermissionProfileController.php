<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\DashboardPermissionProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminPermissionProfileController extends Controller
{
    public function index(Request $request)
    {
        $this->ensureSuperAdministrator($request);

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
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureSuperAdministrator($request);
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
        $this->ensureSuperAdministrator($request);
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
        $this->ensureSuperAdministrator($request);
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
        $this->ensureSuperAdministrator($request);
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
    private function validatedProfileData(Request $request, ?DashboardPermissionProfile $profile = null): array
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
            'permissions' => DashboardPermissionProfile::normalizePermissions($data['permissions'] ?? []),
        ];
    }

    private function ensureSuperAdministrator(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
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
