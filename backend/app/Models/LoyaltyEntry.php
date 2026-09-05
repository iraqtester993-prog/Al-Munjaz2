<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only record for loyalty point movement.  Corrections must be made
 * with a compensating entry, never by editing history.
 */
class LoyaltyEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'loyalty_account_id', 'user_id', 'points', 'balance_after',
        'type', 'source_type', 'source_id', 'note',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'balance_after' => 'integer',
            'source_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('سجل نقاط الولاء غير قابل للتعديل؛ أنشئ قيداً تصحيحياً بدلاً من ذلك.');
        });

        static::deleting(function (): void {
            throw new LogicException('سجل نقاط الولاء غير قابل للحذف.');
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class, 'loyalty_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
