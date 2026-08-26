<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cashbox extends Model
{
    use BelongsToTenant;

    public const KINDS = ['branch', 'vault', 'bank'];

    protected $fillable = [
        'tenant_id', 'branch_id', 'kind', 'name_ar', 'name_en', 'name_ku', 'balance', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(CashboxVoucher::class);
    }
}
