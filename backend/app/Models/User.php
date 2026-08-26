<?php

namespace App\Models;

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
     * type. A general courier can work both legs of a delivery while the
     * specialised roles remain visible to the dashboard, API, and campaigns.
     *
     * @var array<int, string>
     */
    public const COURIER_ROLES = ['courier', 'pickup_courier', 'delivery_courier', 'transporter'];

    /**
     * Transporters work on an inter-branch transfer, never directly on one
     * order. This list protects the direct-order assignment picker from
     * presenting an unsafe, misleading option.
     *
     * @var array<int, string>
     */
    public const DIRECT_ORDER_COURIER_ROLES = ['courier', 'pickup_courier', 'delivery_courier'];

    /** @var array<int, string> */
    public const NOTIFICATION_RECIPIENT_ROLES = ['merchant', ...self::COURIER_ROLES];

    public const STATUSES = ['pending', 'active', 'suspended', 'rejected'];

    protected $fillable = [
        'tenant_id', 'branch_id', 'name', 'username', 'email', 'phone', 'password',
        'role', 'status', 'vehicle', 'shop_name', 'address', 'identity_number', 'theme', 'locale',
        'is_online', 'last_active_at',
        'current_latitude', 'current_longitude', 'location_accuracy_meters', 'location_updated_at',
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
            'last_active_at' => 'datetime',
            'location_updated_at' => 'datetime',
            'current_latitude' => 'decimal:7',
            'current_longitude' => 'decimal:7',
            'location_accuracy_meters' => 'integer',
            'identity_number' => 'encrypted',
            'is_online' => 'boolean',
            'password' => 'hashed',
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

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
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

    public function isCourierRole(): bool
    {
        return in_array($this->role, self::COURIER_ROLES, true);
    }

    public function isActiveUser(): bool
    {
        return $this->status === 'active';
    }
}
