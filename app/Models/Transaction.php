<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const MERCHANT_TX = ['settlement', 'delivery_fee', 'withdrawal'];

    public const COURIER_TX = ['collected', 'returned', 'cash_added', 'paid_order', 'commission'];

    protected $fillable = [
        'tenant_id', 'user_id', 'type', 'amount', 'direction',
        'ref', 'order_id', 'date', 'note',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function signedAmount(): int
    {
        return $this->amount * $this->direction;
    }
}
