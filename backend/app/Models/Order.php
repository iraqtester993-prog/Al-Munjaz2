<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const STATUSES = ['pending', 'approved', 'courier', 'delivered', 'returned'];

    protected $fillable = [
        'tenant_id', 'track_no', 'source',
        'customer_name_ar', 'customer_name_en', 'phone', 'phone2',
        'address_ar', 'address_en', 'order_type', 'price', 'fee',
        'status', 'courier_id', 'branch_id', 'province_id',
        'date', 'notes', 'created_by',
        'accepted_at', 'picked_at', 'delivered_at', 'returned_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'accepted_at' => 'datetime',
            'picked_at' => 'datetime',
            'delivered_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class);
    }

    public function isPayable(): bool
    {
        return in_array($this->status, ['pending', 'approved', 'courier']);
    }
}
