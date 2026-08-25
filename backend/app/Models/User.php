<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    public const ROLES = ['admin', 'owner', 'branch_manager', 'merchant', 'courier', 'pickup_courier', 'delivery_courier', 'transporter', 'support'];

    public const STATUSES = ['pending', 'active', 'suspended', 'rejected'];

    protected $fillable = [
        'tenant_id', 'branch_id', 'name', 'username', 'email', 'phone', 'password',
        'role', 'status', 'vehicle', 'theme', 'locale',
        'is_online', 'last_active_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_active_at' => 'datetime',
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

    public function isActiveUser(): bool
    {
        return $this->status === 'active';
    }
}
