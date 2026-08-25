<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'code', 'name_ar', 'name_en', 'name_ku', 'city', 'phone',
        'address', 'cash_balance', 'is_active',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function originOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'origin_branch_id');
    }

    public function destinationOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'destination_branch_id');
    }
}
