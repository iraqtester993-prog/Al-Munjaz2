<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushNotificationService
{
    public function enabled(): bool
    {
        return filled(config('services.web_push.public_key'))
            && filled(config('services.web_push.private_key'))
            && filled(config('services.web_push.subject'));
    }

    public function publicKey(): ?string
    {
        return $this->enabled() ? (string) config('services.web_push.public_key') : null;
    }

    /**
     * Sends a browser notification for one already-persisted user notification.
     * The campaign dispatcher intentionally creates a row per recipient, so
     * this method never guesses a global audience from an empty user ID.
     */
    public function send(Notification $notification): void
    {
        // A shared-host release can lag behind composer dependencies.  Never
        // let an optional delivery channel break the dashboard send action.
        if (! $this->enabled() || ! class_exists(WebPush::class) || ! $notification->user_id) {
            return;
        }

        $subscriptions = PushSubscription::query()
            ->where('user_id', $notification->user_id)
            ->get()
            ->filter(fn (PushSubscription $subscription) => str_starts_with($subscription->endpoint, 'https://')
                && filter_var($subscription->endpoint, FILTER_VALIDATE_URL));

        if ($subscriptions->isEmpty()) {
            return;
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => config('services.web_push.subject'),
                    'publicKey' => config('services.web_push.public_key'),
                    'privateKey' => config('services.web_push.private_key'),
                ],
            ], [
                'TTL' => 86_400,
                'urgency' => 'high',
            ]);

            foreach ($subscriptions as $subscription) {
                $locale = in_array($subscription->locale, array_keys(config('app.locales')), true)
                    ? $subscription->locale
                    : 'ar';
                $targetUrl = $this->targetUrl($notification);

                $payload = json_encode([
                    'title' => $notification->titleFor($locale),
                    'body' => $notification->bodyFor($locale),
                    'tag' => 'notification-'.$notification->id,
                    'url' => $targetUrl,
                    'icon' => '/assets/icon-192.png',
                    'badge' => '/assets/icon-192.png',
                    'data' => [
                        'notificationId' => $notification->id,
                        'url' => $targetUrl,
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($payload === false) {
                    continue;
                }

                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $subscription->endpoint,
                        'keys' => [
                            'p256dh' => $subscription->p256dh,
                            'auth' => $subscription->auth,
                        ],
                        'contentEncoding' => 'aes128gcm',
                    ]),
                    $payload,
                    ['topic' => 'notification-'.$notification->id],
                );
            }

            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    continue;
                }

                if ($report->isSubscriptionExpired()) {
                    PushSubscription::query()->where('endpoint', $report->getEndpoint())->delete();

                    continue;
                }

                Log::warning('Web Push delivery failed.', [
                    'notification_id' => $notification->id,
                    'endpoint' => $report->getEndpoint(),
                    'reason' => $report->getReason(),
                ]);
            }
        } catch (\Throwable $exception) {
            // Device delivery must never turn a successful in-app campaign or
            // order update into a server error. The inbox row remains intact.
            Log::warning('Web Push delivery could not be processed.', [
                'notification_id' => $notification->id,
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    private function targetUrl(Notification $notification): string
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $explicitUrl = $data['url'] ?? null;

        if (is_string($explicitUrl) && str_starts_with($explicitUrl, '/app/') && ! str_contains($explicitUrl, '//')) {
            return $explicitUrl;
        }

        $chatId = filter_var($data['chat_id'] ?? null, FILTER_VALIDATE_INT);
        if ($chatId) {
            return '/app/chats/'.$chatId;
        }

        $orderId = filter_var($data['order_id'] ?? null, FILTER_VALIDATE_INT);
        if ($orderId) {
            return '/app/orders?open='.$orderId.'&list=1';
        }

        return '/app/notifications?open='.$notification->id;
    }
}
