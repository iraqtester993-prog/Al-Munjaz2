<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashboxVoucher extends Model
{
    use BelongsToTenant;

    public const TYPES = ['receipt', 'payment', 'transfer_out', 'transfer_in', 'opening_balance', 'courier_handover', 'merchant_settlement'];

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
