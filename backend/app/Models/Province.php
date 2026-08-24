<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Province extends Model
{
    protected $fillable = [
        'tenant_id', 'name_ar', 'name_en', 'name_ku', 'sort_order',
    ];
}
