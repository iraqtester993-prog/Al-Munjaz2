<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Province extends Model
{
    protected $fillable = [
        'tenant_id', 'name_ar', 'name_en', 'name_ku', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Provinces are shared platform reference data, while the nullable
     * tenant_id keeps historical tenant-specific records compatible.
     * Management screens must only ever mutate the shared records.
     *
     * @param  Builder<Province>  $query
     * @return Builder<Province>
     */
    public function scopePlatform(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('tenant_id'));
    }

    /**
     * @param  Builder<Province>  $query
     * @return Builder<Province>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_active'), true);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }
}
