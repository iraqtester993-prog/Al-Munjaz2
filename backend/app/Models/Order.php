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

    public const STATUSES = ['pending', 'approved', 'courier', 'delivered', 'returned', 'cancelled', 'damaged'];

    public const WORKFLOW_STAGES = ['created', 'awaiting_pickup', 'pickup_assigned', 'picked_up', 'at_origin_branch', 'sorting', 'awaiting_transfer', 'in_transfer', 'at_destination_branch', 'delivery_assigned', 'out_for_delivery', 'delivered', 'financially_closed'];

    protected $fillable = [
        'tenant_id', 'track_no', 'source',
        'customer_name_ar', 'customer_name_en', 'phone', 'phone2',
        'address_ar', 'address_en', 'order_type', 'price', 'fee',
        'status', 'workflow_stage', 'courier_id', 'branch_id', 'origin_branch_id', 'destination_branch_id',
        'merchant_id', 'pickup_courier_id', 'delivery_courier_id', 'province_id',
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

    public function originBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'origin_branch_id');
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_id');
    }

    public function pickupCourier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pickup_courier_id');
    }

    public function deliveryCourier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivery_courier_id');
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(OrderMovement::class)->latest('occurred_at');
    }

    public function isPayable(): bool
    {
        return in_array($this->status, ['pending', 'approved', 'courier']);
    }
}
