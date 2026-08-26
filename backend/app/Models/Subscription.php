<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscription extends Model
{
    use SoftDeletes;

    public const STATUSES = ['trial', 'active', 'suspended', 'cancelled', 'expired'];

    public const BILLING_PERIODS = ['monthly', 'annual'];

    protected $fillable = [
        'tenant_id', 'plan_id', 'status', 'billing_period', 'amount',
        'starts_at', 'ends_at', 'next_invoice_at', 'auto_renew',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'next_invoice_at' => 'datetime',
            'auto_renew' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function isCurrent(): bool
    {
        return in_array($this->status, ['trial', 'active'], true)
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }
}
