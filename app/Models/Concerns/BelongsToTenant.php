<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Tenancy\TenantContext;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function ($model) {
            if (TenantContext::enabled() && empty($model->tenant_id)) {
                $model->tenant_id = TenantContext::id();
            }
        });
    }
}
