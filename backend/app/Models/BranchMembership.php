<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)
            ->withoutGlobalScope(TenantScope::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Atomically move an account's dashboard boundary to one branch.
     *
     * `primary_user_id` mirrors user_id only for the primary row. Its unique
     * index is a portable database-level guarantee that MySQL and SQLite can
     * enforce even though both allow many NULL values in a unique index.
     */
    public static function assignPrimary(User $user, Branch $branch, string $accessRole): self
    {
        if (! in_array($accessRole, self::ACCESS_ROLES, true)) {
            throw new InvalidArgumentException('Unsupported branch membership role.');
        }

        return DB::transaction(function () use ($user, $branch, $accessRole): self {
            // Serialise changes per user. This closes the otherwise subtle
            // race where two administrators select different primary
            // branches at the same time.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            static::query()
                ->where('user_id', $user->id)
                ->update([
                    'is_primary' => false,
                    'primary_user_id' => null,
                    'updated_at' => now(),
                ]);

            $membership = static::query()->firstOrNew([
                'branch_id' => $branch->id,
                'user_id' => $user->id,
            ]);
            $membership->access_role = $accessRole;
            $membership->is_primary = true;
            $membership->save();

            return $membership;
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePrimary(Builder $query): Builder
    {
        return $query
            ->where('is_primary', true)
            ->whereColumn('primary_user_id', 'user_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $membership): void {
            $membership->primary_user_id = $membership->is_primary
                ? $membership->user_id
                : null;
        });
    }
}
