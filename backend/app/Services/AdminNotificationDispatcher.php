<?php

namespace App\Services;

use App\Events\AdminNotificationDispatched;
use App\Models\Notification;
use App\Models\NotificationCampaign;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates the delivery records used by the mobile in-app inbox.
 *
 * Keep any browser/device push implementation out of the controller and add
 * it as a listener for AdminNotificationDispatched. This guarantees that push
 * delivery always mirrors committed in-app records.
 */
class AdminNotificationDispatcher
{
    /**
     * @param array{
     *     audience:string,
     *     target_user_id?:int|null,
     *     type:string,
     *     title_ar:string,
     *     title_en?:string|null,
     *     title_ku?:string|null,
     *     body_ar?:string|null,
     *     body_en?:string|null,
     *     body_ku?:string|null
     * } $payload
     */
    public function dispatch(User $actor, array $payload): NotificationCampaign
    {
        // FormRequest::validated() omits nullable fields that were not sent.
        // Normalize them once so direct callers and future API clients can send
        // a localized Arabic-only campaign without triggering an undefined-key
        // error while the campaign records are being created.
        $payload += [
            'target_user_id' => null,
            'title_en' => null,
            'title_ku' => null,
            'body_ar' => null,
            'body_en' => null,
            'body_ku' => null,
        ];

        return DB::transaction(function () use ($actor, $payload): NotificationCampaign {
            $recipients = $this->recipientsFor($payload)->get(['id', 'tenant_id']);

            if ($recipients->isEmpty()) {
                throw ValidationException::withMessages([
                    'audience' => __('No active recipients match the selected audience.'),
                ]);
            }

            $campaign = NotificationCampaign::create([
                'created_by' => $actor->id,
                'audience' => $payload['audience'],
                'target_user_id' => in_array($payload['audience'], NotificationCampaign::TARGETED_AUDIENCES, true)
                    ? $payload['target_user_id']
                    : null,
                'type' => $payload['type'],
                'title_ar' => $payload['title_ar'],
                'title_en' => $payload['title_en'] ?: null,
                'title_ku' => $payload['title_ku'] ?: null,
                'body_ar' => $payload['body_ar'] ?: null,
                'body_en' => $payload['body_en'] ?: null,
                'body_ku' => $payload['body_ku'] ?: null,
                'recipient_count' => $recipients->count(),
                'sent_at' => now(),
            ]);

            $notificationIds = [];
            $now = now();

            // Eloquent creation keeps one consistent format for JSON data and
            // lets future model observers cover dashboard-created alerts too.
            foreach ($recipients as $recipient) {
                $delivery = Notification::create([
                    'campaign_id' => $campaign->id,
                    'tenant_id' => $recipient->tenant_id,
                    'user_id' => $recipient->id,
                    'type' => $payload['type'],
                    'title_ar' => $payload['title_ar'],
                    'title_en' => $payload['title_en'] ?: null,
                    'title_ku' => $payload['title_ku'] ?: null,
                    'body_ar' => $payload['body_ar'] ?: null,
                    'body_en' => $payload['body_en'] ?: null,
                    'body_ku' => $payload['body_ku'] ?: null,
                    'data' => [
                        'source' => 'dashboard',
                        'campaign_id' => $campaign->id,
                        'audience' => $campaign->audience,
                        'recipient_user_id' => $recipient->id,
                        'recipient_tenant_id' => $recipient->tenant_id,
                        'sent_at' => $now->toIso8601String(),
                    ],
                    'created_at' => $now,
                ]);

                $notificationIds[] = $delivery->id;
            }

            DB::afterCommit(function () use ($campaign, $recipients, $notificationIds): void {
                AdminNotificationDispatched::dispatch(
                    $campaign->id,
                    $recipients->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    $recipients->pluck('tenant_id')->map(fn ($id) => $id === null ? null : (int) $id)->all(),
                    $notificationIds,
                );
            });

            return $campaign;
        });
    }

    /**
     * Resolve only active mobile-app accounts.  In particular, `all` never
     * includes dashboard users merely because they happen to have an active
     * account in the same users table.
     *
     * @param  array{audience:string,target_user_id?:int|null}  $payload
     */
    private function recipientsFor(array $payload): Builder
    {
        // The platform dashboard may send to every active app account. Do
        // not let the host-selected tenant scope silently reduce an “all”
        // campaign to a single tenant.
        $query = User::withoutGlobalScope(TenantScope::class)
            ->whereIn('role', User::NOTIFICATION_RECIPIENT_ROLES)
            ->where('status', 'active')
            ->orderBy('id');

        return match ($payload['audience']) {
            'merchants' => $query->where('role', 'merchant'),
            'merchant' => $query->where('role', 'merchant')->whereKey($payload['target_user_id']),
            'couriers' => $query->whereIn('role', User::COURIER_ROLES),
            'courier' => $query->whereIn('role', User::COURIER_ROLES)->whereKey($payload['target_user_id']),
            'pickup_couriers' => $query->where('role', 'pickup_courier'),
            'delivery_couriers' => $query->where('role', 'delivery_courier'),
            'transporters' => $query->where('role', 'transporter'),
            'user' => $query->whereKey($payload['target_user_id']),
            default => $query,
        };
    }
}
