<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinanceRequest extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const CASH_HANDOVER = 'cash_handover';

    public const BUDGET_RECHARGE = 'budget_recharge';

    /**
     * A customer-visible Qi payment declaration. It remains pending until an
     * administrator verifies the provider reference and credits the courier
     * wallet. The provider integration can later approve the same request
     * programmatically without changing the ledger contract.
     */
    public const QI_TOPUP = 'qi_topup';

    public const MERCHANT_PAYOUT = 'merchant_payout';

    public const TYPES = [
        self::CASH_HANDOVER,
        self::BUDGET_RECHARGE,
        self::QI_TOPUP,
        self::MERCHANT_PAYOUT,
    ];

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED];

    protected $fillable = [
        'tenant_id', 'user_id', 'branch_id', 'type', 'amount', 'approved_amount',
        'status', 'reference', 'external_reference', 'note', 'decision_note', 'processed_by', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
