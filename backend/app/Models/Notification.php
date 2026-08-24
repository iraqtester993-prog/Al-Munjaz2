<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Notification extends Model
{
    use BelongsToTenant, SoftDeletes;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'user_id', 'type', 'title_ar', 'title_en', 'title_ku',
        'body_ar', 'body_en', 'body_ku', 'read_at', 'data', 'dedup_key',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'read_at' => 'datetime',
            'data' => 'array',
        ];
    }

    public function titleFor(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->{'title_'.$locale} ?? $this->title_ar;
    }

    public function bodyFor(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $this->{'body_'.$locale} ?? ($this->body_ar ?? '');
    }
}
