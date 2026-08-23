<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'user_id', 'action', 'subject_type',
        'subject_id', 'data', 'ip',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'data' => 'array',
        ];
    }
}
