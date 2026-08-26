<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationCampaign extends Model
{
    public const AUDIENCES = [
        'all',
        'merchants',
        'couriers',
        'pickup_couriers',
        'delivery_couriers',
        'transporters',
        'user',
    ];

    protected $fillable = [
        'created_by',
        'audience',
        'target_user_id',
        'type',
        'title_ar',
        'title_en',
        'title_ku',
        'body_ar',
        'body_en',
        'body_ku',
        'recipient_count',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'campaign_id');
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
