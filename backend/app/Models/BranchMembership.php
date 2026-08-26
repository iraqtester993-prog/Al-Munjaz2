<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An explicit, auditable dashboard-access grant for an operational branch.
 *
 * This is intentionally not tenant-scoped. The branch membership is the
 * access boundary itself, while the portal controller still limits every
 * branch and order query to the authenticated user's memberships.
 */
class BranchMembership extends Model
{
    public const OWNER = 'owner';

    public const MANAGER = 'manager';

    /** @var array<int, string> */
    public const ACCESS_ROLES = [self::OWNER, self::MANAGER];

    protected $fillable = [
        'branch_id',
        'user_id',
        'access_role',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)
            ->withoutGlobalScope(TenantScope::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
