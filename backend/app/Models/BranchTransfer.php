<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BranchTransfer extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'origin_branch_id', 'destination_branch_id', 'transporter_id',
        'reference', 'status', 'dispatched_at', 'received_at', 'notes',
    ];

    protected function casts(): array
    {
        return ['dispatched_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function originBranch(): BelongsTo { return $this->belongsTo(Branch::class, 'origin_branch_id'); }
    public function destinationBranch(): BelongsTo { return $this->belongsTo(Branch::class, 'destination_branch_id'); }
    public function transporter(): BelongsTo { return $this->belongsTo(User::class, 'transporter_id'); }
    public function orders(): BelongsToMany { return $this->belongsToMany(Order::class, 'branch_transfer_orders')->withTimestamps(); }
}
