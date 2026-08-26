<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const STATUSES = ['pending', 'approved', 'courier', 'delivered', 'returned', 'cancelled', 'damaged', 'rejected'];

    /**
     * "late" is a calculated exception, not a persisted state. Replacing an
     * order's actual state with "late" would destroy whether it is pending,
     * approved, or currently with a courier, so it is safe as a filter only.
     *
     * @var array<int, string>
     */
    public const FILTERABLE_STATUSES = [...self::STATUSES, 'late'];

    /** @var array<int, string> */
    public const TERMINAL_STATUSES = ['delivered', 'returned', 'cancelled', 'damaged', 'rejected'];

    public const WORKFLOW_STAGES = ['created', 'awaiting_pickup', 'pickup_assigned', 'picked_up', 'at_origin_branch', 'sorting', 'awaiting_transfer', 'in_transfer', 'at_destination_branch', 'delivery_assigned', 'out_for_delivery', 'delivered', 'return_pending_merchant', 'returned_to_merchant', 'returned', 'cancelled', 'damaged', 'rejected', 'financially_closed'];

    protected $fillable = [
        'tenant_id', 'track_no', 'source',
        'customer_name_ar', 'customer_name_en', 'phone', 'phone2',
        'address_ar', 'address_en', 'pickup_latitude', 'pickup_longitude', 'pickup_location_label',
        'order_type', 'delivery_vehicle', 'vehicle_note', 'weight_grams', 'price', 'fee', 'return_fee', 'return_fee_applied', 'pricing_rule_id',
        'status', 'workflow_stage', 'courier_id', 'branch_id', 'origin_branch_id', 'destination_branch_id',
        'merchant_id', 'pickup_courier_id', 'delivery_courier_id', 'province_id',
        'date', 'notes', 'created_by',
        'accepted_at', 'picked_at', 'delivered_at', 'returned_at', 'returned_to_merchant_at', 'return_fee_charged_at', 'pickup_deadline_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'accepted_at' => 'datetime',
            'picked_at' => 'datetime',
            'delivered_at' => 'datetime',
            'returned_at' => 'datetime',
            'returned_to_merchant_at' => 'datetime',
            'return_fee_charged_at' => 'datetime',
            'pickup_deadline_at' => 'datetime',
            'pickup_latitude' => 'decimal:7',
            'pickup_longitude' => 'decimal:7',
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
        // A route may use a platform-managed branch owned by the operational
        // tenant rather than by the merchant that owns the order. The order
        // itself is authorised/scoped first; this relation must then be able
        // to resolve that explicitly assigned shared branch.
        return $this->belongsTo(Branch::class)->withoutGlobalScope(TenantScope::class);
    }

    public function originBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'origin_branch_id')->withoutGlobalScope(TenantScope::class);
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id')->withoutGlobalScope(TenantScope::class);
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

    public static function isTerminalStatus(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES, true);
    }

    /**
     * Apply a dashboard/API status filter without turning an elapsed deadline
     * into a destructive status transition.
     */
    public function scopeOperationalStatus($query, string $status)
    {
        if ($status === 'late') {
            return $query
                ->whereNotIn('status', self::TERMINAL_STATUSES)
                ->whereNotNull('pickup_deadline_at')
                ->where('pickup_deadline_at', '<', now());
        }

        return $query->where('status', $status);
    }
}
