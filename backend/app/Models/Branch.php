<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'is_platform_managed', 'code', 'name_ar', 'name_en', 'name_ku', 'city', 'phone',
        'address', 'cash_balance', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_platform_managed' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Explicit dashboard access grants.  `users()` remains the legacy
     * one-branch operational assignment relation and must not be used as the
     * authorisation source for the branch portal.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'branch_memberships')
            ->withPivot('access_role')
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(BranchMembership::class);
    }

    public function originOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'origin_branch_id');
    }

    public function destinationOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'destination_branch_id');
    }

    /**
     * A platform-managed branch is part of the delivery network and can be
     * selected for any merchant order. A tenant-owned branch stays private to
     * that merchant tenant.
     */
    public function canServeTenant(int $tenantId): bool
    {
        return $this->is_active
            && ($this->is_platform_managed || (int) $this->tenant_id === $tenantId);
    }

    /**
     * Limit branch choices to the active shared network plus the current
     * merchant's own branches. This is the same rule enforced on write.
     *
     * @param  Builder<Branch>  $query
     * @return Builder<Branch>
     */
    public function scopeAvailableForTenant(Builder $query, int $tenantId): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(function (Builder $branches) use ($tenantId): void {
                $branches
                    ->where('is_platform_managed', true)
                    ->orWhere('tenant_id', $tenantId);
            });
    }
}
