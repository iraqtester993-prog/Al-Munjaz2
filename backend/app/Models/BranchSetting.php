<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A branch-owned override for an otherwise platform-wide setting.
 *
 * Branch settings are intentionally not tenant-scoped.  The owning branch is
 * the authorisation boundary and every dashboard write resolves that branch
 * from the authenticated account's server-owned branch scope.
 */
class BranchSetting extends Model
{
    protected $fillable = [
        'branch_id', 'key', 'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
