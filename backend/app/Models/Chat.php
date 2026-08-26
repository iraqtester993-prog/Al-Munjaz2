<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'user_id', 'counterparty_type', 'counterparty_id',
        'order_id', 'title_ar', 'title_en', 'last_message', 'last_at', 'unread',
        'user_read_at', 'admin_read_at',
    ];

    protected function casts(): array
    {
        return [
            'last_at' => 'datetime',
            'user_read_at' => 'datetime',
            'counterparty_read_at' => 'datetime',
            'admin_read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The delivery order attached to an order conversation.
     *
     * Couriers are intentionally isolated into their own tenant, while an
     * order belongs to its merchant tenant.  This relation is only consumed
     * after the caller has passed the explicit participant check in the chat
     * controller, so it must not inherit the ambient tenant scope.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)
            ->withoutGlobalScope(TenantScope::class)
            ->withTrashed();
    }

    /**
     * Direct order chats are owned by the merchant tenant, but an assigned
     * courier may belong to another tenant.  Resolve the route model without
     * TenantScope and let the controller's explicit participant policy make
     * the final access decision.  This prevents a valid courier request from
     * turning into a misleading 404 during implicit route binding.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::withoutGlobalScope(TenantScope::class)
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }
}
