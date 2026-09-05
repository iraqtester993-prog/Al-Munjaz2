<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Services\BranchDashboardContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public const ROLES = ['admin', 'owner', 'branch_manager', 'merchant', 'courier', 'pickup_courier', 'delivery_courier', 'transporter', 'support'];

    /**
     * Operational accounts are explicit rather than inferred from a vehicle
     * type. A general courier now performs the whole direct-order journey.
     * Specialist values remain only so legacy account/history data and the
     * separate inter-branch transporter workflow can be retained safely.
     *
     * @var array<int, string>
     */
    public const COURIER_ROLES = ['courier', 'pickup_courier', 'delivery_courier', 'transporter'];

    /**
     * A direct order has one accountable courier from pickup through delivery.
     * The legacy pickup/delivery account roles remain in the system for
     * historical records and non-order administration, but cannot be selected
     * for a new direct-order assignment.
     *
     * @var array<int, string>
     */
    public const DIRECT_ORDER_COURIER_ROLES = ['courier'];

    /** @var array<int, string> */
    public const NOTIFICATION_RECIPIENT_ROLES = ['merchant', ...self::COURIER_ROLES];

    public const STATUSES = ['pending', 'active', 'suspended', 'rejected'];

    /**
     * Explicit, human-readable capabilities for a branch dashboard account.
     * An administrator is always allowed every capability; these values only
     * constrain owner/manager accounts to their authorised branches.
     *
     * @var array<int, string>
     */
    public const DASHBOARD_PERMISSIONS = [
        'overview',
        'orders',
        'merchants',
        'couriers',
        'courier_locations',
        'notifications',
        'finance',
        'settings',
    ];

    protected $fillable = [
        'tenant_id', 'branch_id', 'name', 'username', 'email', 'phone', 'password',
        'role', 'status', 'vehicle', 'admin_deduction_per_order', 'shop_name', 'address', 'identity_number', 'theme', 'locale',
        'merchant_pickup_latitude', 'merchant_pickup_longitude', 'merchant_pickup_location_label', 'merchant_pickup_location_updated_at',
        'merchant_verified_at', 'merchant_verified_by',
        'courier_verified', 'courier_verified_at', 'courier_verified_by',
        'is_online', 'last_active_at',
        'current_latitude', 'current_longitude', 'location_accuracy_meters', 'location_updated_at',
        'dashboard_permissions',
        'permission_profile_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'merchant_verified_at' => 'datetime',
            'courier_verified' => 'boolean',
            'courier_verified_at' => 'datetime',
            'last_active_at' => 'datetime',
            'merchant_pickup_location_updated_at' => 'datetime',
            'merchant_pickup_latitude' => 'decimal:7',
            'merchant_pickup_longitude' => 'decimal:7',
            'location_updated_at' => 'datetime',
            'current_latitude' => 'decimal:7',
            'current_longitude' => 'decimal:7',
            'location_accuracy_meters' => 'integer',
            'identity_number' => 'encrypted',
            'is_online' => 'boolean',
            'admin_deduction_per_order' => 'integer',
            'password' => 'hashed',
            'dashboard_permissions' => 'array',
            'is_super_admin' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Named platform-dashboard permissions are deliberately separate from
     * branch dashboard permissions. The latter remain attached to branch
     * memberships and never inherit a platform profile.
     */
    public function permissionProfile(): BelongsTo
    {
        return $this->belongsTo(DashboardPermissionProfile::class, 'permission_profile_id');
    }

    /**
     * The administrator who explicitly approved the public merchant badge.
     * Documents and activation are intentionally separate concerns, so a
     * merchant never receives a public verification mark by accident.
     */
    public function merchantVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merchant_verified_by')->withTrashed();
    }

    /**
     * The administrator who granted the courier's operational approval.
     * This is intentionally distinct from the merchant's public badge.
     */
    public function courierVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'courier_verified_by')->withTrashed();
    }

    /**
     * The operational branches this dashboard account may open.  This is
     * deliberately a separate relation from the historical `branch_id`
     * column: an owner can be responsible for several branches without
     * turning one mutable profile field into an authorisation boundary.
     */
    public function managedBranches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_memberships')
            ->withoutGlobalScope(TenantScope::class)
            ->withPivot('access_role', 'is_primary')
            ->withTimestamps();
    }

    /**
     * Exposes the explicit access grants for auditing and safe portal
     * scoping.  Callers should use `managedBranches()` when they need the
     * related branch records themselves.
     */
    public function branchMemberships(): HasMany
    {
        return $this->hasMany(BranchMembership::class);
    }

    /**
     * The durable primary membership used by the full branch dashboard.
     * The relationship is separate from legacy branch_id so a stale profile
     * field cannot widen the author's server-side scope.
     */
    public function primaryBranchMembership(): HasOne
    {
        return $this->hasOne(BranchMembership::class)
            ->primary();
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    /**
     * Loyalty points remain deliberately separate from monetary wallets.
     */
    public function loyaltyAccount(): HasOne
    {
        return $this->hasOne(LoyaltyAccount::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'courier_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function provinces(): BelongsToMany
    {
        return $this->belongsToMany(Province::class)->withPivot('is_primary')->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * The explicit super-admin flag is the only unrestricted platform
     * dashboard bypass. A role=admin account without a profile is denied;
     * this keeps newly invited staff from receiving inherited full access.
     */
    public function isSuperAdmin(): bool
    {
        return $this->isAdmin() && (bool) $this->is_super_admin;
    }

    public function isCourierRole(): bool
    {
        return in_array($this->role, self::COURIER_ROLES, true);
    }

    public function isActiveUser(): bool
    {
        return $this->status === 'active';
    }

    public function isMerchantVerified(): bool
    {
        return $this->role === 'merchant' && $this->merchant_verified_at !== null;
    }

    /**
     * A direct courier can receive a new order only after an administrator
     * has reviewed and explicitly approved the account. Existing courier
     * records are grandfathered by the migration default; registration sets
     * this flag to false for every newly created courier.
     */
    public function isCourierVerified(): bool
    {
        return $this->role === 'courier' && (bool) $this->courier_verified;
    }

    /**
     * A branch dashboard profile is usable only while it still has an
     * explicit membership in at least one active platform branch. This is
     * intentionally independent from the legacy `branch_id` field so a
     * bookmarked portal URL cannot outlive a deactivated membership.
     */
    public function hasActiveBranchPortalAccess(): bool
    {
        return app(BranchDashboardContext::class)->hasActiveBranchAccess($this);
    }

    /**
     * Platform administrators are the system owners and never receive a
     * mutable per-screen restriction.  Branch accounts are restricted to an
     * explicit allow-list so an account created for one function cannot gain
     * access merely by discovering a route.
     */
    public function canUseDashboardPermission(string $permission): bool
    {
        if ($this->isAdmin() || $this->role === 'owner') {
            return true;
        }

        return in_array($permission, $this->dashboard_permissions ?? [], true);
    }

    /**
     * Checks the effective dashboard profile. Branch accounts deliberately
     * never inherit a platform profile: a branch manager without a profile
     * is the principal manager for their one scoped branch, while a manager
     * with a branch-owned profile is a restricted local system employee.
     * Route middleware still rejects non-local modules (platform, provinces,
     * and structural controls) even when this helper is used for UI flags.
     */
    public function canUseAdminPermission(string $module, string $action): bool
    {
        if ($this->isAdmin()) {
            if ($this->isSuperAdmin()) {
                return true;
            }

            return $this->permissionProfile?->allows($module, $action) ?? false;
        }

        if ($this->role !== 'branch_manager') {
            return false;
        }

        $scope = app(BranchDashboardContext::class)->scopeFor($this);
        if (! $scope->hasBranchScope()) {
            return false;
        }

        // The original branch manager has no mutable profile. A profile is
        // required for every local employee and must belong to this exact
        // branch; a global profile is never a valid branch grant.
        if ($this->permission_profile_id === null) {
            return true;
        }

        return $this->permissionProfile
            && (int) $this->permissionProfile->branch_id === (int) $scope->branchId()
            && $this->permissionProfile->allows($module, $action);
    }

    /**
     * The dashboard home is deliberately a route with a `view` capability.
     * Sending a limited operator to the aggregate root would immediately
     * yield a 403 (and, more importantly, would risk a broad data response).
     */
    public function firstAdminDashboardPath(): ?string
    {
        if ($this->isAdmin()) {
            if ($this->isSuperAdmin()) {
                return '/dashboard';
            }
        } elseif ($this->role === 'branch_manager') {
            $scope = app(BranchDashboardContext::class)->scopeFor($this);
            if (! $scope->hasBranchScope()) {
                return null;
            }

            if ($this->permission_profile_id === null) {
                return '/dashboard';
            }
        } else {
            return null;
        }

        foreach ([
            ['orders', '/dashboard/orders'],
            ['merchants', '/dashboard/merchants'],
            ['couriers', '/dashboard/couriers'],
            ['courier_locations', '/dashboard/couriers/locations'],
            ['branches', '/dashboard/branches'],
            ['finance', '/dashboard/finance'],
            ['cashboxes', '/dashboard/cashboxes'],
            ['pricing', '/dashboard/pricing'],
            ['reports', '/dashboard/reports'],
            ['notifications', '/dashboard/notifications'],
            ['provinces', '/dashboard/settings?tab=provinces'],
            ['content', '/dashboard/settings?tab=slider'],
            ['loyalty', '/dashboard/loyalty'],
            ['chat', '/dashboard/chat'],
            ['transfers', '/dashboard/transfers'],
            ['settings', '/dashboard/settings'],
            ['platform', '/dashboard/platform'],
        ] as [$module, $path]) {
            if ($this->canUseAdminPermission($module, 'view')) {
                return $path;
            }
        }

        return null;
    }
}
