<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashboxVoucher extends Model
{
    use BelongsToTenant;

    /**
     * Cashboxes are a custody ledger for courier delivery collections only.
     *
     * `courier_handover` is the sole external source of money.  The transfer
     * rows only move that same collected money between an operating branch,
     * the vault, and the bank; they must never change the platform total.
     *
     * Older installations can still contain legacy labels such as `receipt`
     * or `opening_balance`.  They remain readable database values, but new
     * code must not create or include them in collection reporting.
     *
     * @var array<int, string>
     */
    public const COLLECTION_CUSTODY_TYPES = ['courier_handover', 'transfer_out', 'transfer_in'];

    /** @var array<int, string> */
    public const TYPES = self::COLLECTION_CUSTODY_TYPES;

    protected $fillable = [
        'tenant_id', 'cashbox_id', 'counterparty_cashbox_id', 'branch_id', 'actor_id', 'type', 'direction',
        'amount', 'reference', 'note', 'meta', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array', 'occurred_at' => 'datetime'];
    }

    public function cashbox(): BelongsTo
    {
        return $this->belongsTo(Cashbox::class);
    }

    public function counterpartyCashbox(): BelongsTo
    {
        return $this->belongsTo(Cashbox::class, 'counterparty_cashbox_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
