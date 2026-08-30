<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\DashboardInvitation;
use App\Models\DashboardPermissionProfile;
use App\Models\User;
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
        $this->ensureSuperAdministrator($request);

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
                'id', 'tenant_id', 'permission_profile_id', 'name', 'username', 'email', 'phone',
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
        ]);
    }

    /**
     * Provisioning remains invitation-only: the recipient selects their own
     * username, phone, and password through the existing one-time acceptance
     * flow.  This avoids an administrator handling another employee's secret.
     */
    public function invite(Request $request)
    {
        $this->ensureSuperAdministrator($request);

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
     * Editing staff deliberately excludes role, status, passwords, and the
     * super-admin flag. Each one has either a separate safe flow or no public
     * mutation route at all.
     */
    public function update(Request $request, User $user)
    {
        $this->ensureSuperAdministrator($request);
        $this->ensureEmployee($user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'min:3', 'max:60', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['required', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($user->id)],
            'permission_profile_id' => ['nullable', 'integer', Rule::exists('dashboard_permission_profiles', 'id')],
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
            'username' => trim($data['username']),
            'email' => Str::lower(trim($data['email'])),
            'phone' => trim($data['phone']),
        ];
        if (! $user->isSuperAdmin() && array_key_exists('permission_profile_id', $data)) {
            $attributes['permission_profile_id'] = $data['permission_profile_id'];
        }

        $user->update($attributes);

        $this->record($request, 'dashboard.employee.updated', $user, [
            'before' => $before,
            'fields' => array_keys($attributes),
            'permission_profile_id' => $user->permission_profile_id,
        ]);

        return back()->with('success', __('Employee account updated.'));
    }

    public function status(Request $request, User $user)
    {
        $this->ensureSuperAdministrator($request);
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
        $this->ensureSuperAdministrator($request);
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

    private function ensureSuperAdministrator(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
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
            'username' => $employee->username,
            'email' => $employee->email,
            'phone' => $employee->phone,
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
