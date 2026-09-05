<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wallet extends Model
{
    protected $fillable = [
        'user_id', 'balance', 'budget', 'budget_balance',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'budget' => 'integer',
            'budget_balance' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $wallet): void {
            // Keep direct model creates (seeders, tests, registration) in
            // sync with the new two-value budget model. Bulk seeders set the
            // value explicitly because Eloquent events do not run for them.
            if (! array_key_exists('budget_balance', $wallet->getAttributes())) {
                $wallet->budget_balance = max(0, (int) ($wallet->budget ?? 0));
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
