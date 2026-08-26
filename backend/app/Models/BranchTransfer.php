<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BranchTransfer extends Model
{
    use BelongsToTenant;

    public const DRAFT = 'draft';

    public const DISPATCHED = 'dispatched';

    public const RECEIVED = 'received';

    public const STATUSES = [self::DRAFT, self::DISPATCHED, self::RECEIVED];

    protected $fillable = [
        'tenant_id', 'origin_branch_id', 'destination_branch_id', 'transporter_id',
        'reference', 'status', 'dispatched_at', 'received_at', 'notes',
    ];

    protected function casts(): array
    {
        return ['dispatched_at' => 'datetime', 'received_at' => 'datetime'];
    }

    /**
     * Transfers are visible to platform operations even when one endpoint is
     * a merchant-owned branch. The transfer itself is authorised first; these
     * relations must therefore resolve the historical branch without the
     * request tenant scope hiding it.
     */
    public function originBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'origin_branch_id')->withoutGlobalScope(TenantScope::class)->withTrashed();
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id')->withoutGlobalScope(TenantScope::class)->withTrashed();
    }

    public function transporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transporter_id');
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'branch_transfer_orders')->withoutGlobalScope(TenantScope::class)->withTrashed()->withTimestamps();
    }
}
