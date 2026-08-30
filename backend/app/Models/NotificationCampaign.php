<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationCampaign extends Model
{
    /**
     * A campaign always targets mobile application accounts.  Dashboard
     * accounts (administrator, owner, branch manager, and support) are kept
     * out of this list by the dispatcher, even when the audience is `all`.
     *
     * The specialised courier audiences and `user` are retained for existing
     * saved campaigns and integrations.  New dashboard targeting should use
     * `merchant` and `courier` for one selected account so its role remains
     * part of the auditable campaign record.
     *
     * @var array<int, string>
     */
    public const AUDIENCES = [
        'all',
        'merchants',
        'merchant',
        'couriers',
        'courier',
        'pickup_couriers',
        'delivery_couriers',
        'transporters',
        'user',
    ];

    /** @var array<int, string> */
    public const TARGETED_AUDIENCES = ['merchant', 'courier', 'user'];

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
