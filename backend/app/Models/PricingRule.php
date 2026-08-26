<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingRule extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'merchant_id', 'origin_province_id', 'destination_province_id', 'service', 'vehicle',
        'min_weight_grams', 'max_weight_grams', 'base_fee', 'return_fee', 'priority', 'is_active',
        'name_ar', 'name_en', 'name_ku',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_id');
    }

    public function originProvince(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'origin_province_id');
    }

    public function destinationProvince(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'destination_province_id');
    }
}
