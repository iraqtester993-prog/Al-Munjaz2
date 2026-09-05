<?php

namespace App\Services;

use App\Models\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Delivers an already-persisted inbox notification to the user's OneSignal
 * subscriptions.  The inbox record remains the source of truth if a device
 * is offline or has disabled push permission.
 */
class OneSignalNotificationService
{
    public function enabled(): bool
    {
        return filled(config('onesignal.app_id'))
            && filled(config('onesignal.app_api_key'));
    }

    public function send(Notification $notification): void
    {
        if (! $this->enabled() || ! $notification->user_id) {
            return;
        }

        $locale = $notification->user?->locale ?: 'ar';
        $targetUrl = $this->targetUrl($notification);
        $data = is_array($notification->data) ? $notification->data : [];
        $channelId = $this->channelId($notification);

        try {
            $response = Http::acceptJson()
                ->withToken(config('onesignal.app_api_key'), 'Key')
                ->timeout(8)
                ->post('https://api.onesignal.com/notifications', [
                    'app_id' => config('onesignal.app_id'),
                    'target_channel' => 'push',
                    'include_aliases' => [
                        'external_id' => [$this->externalId((int) $notification->user_id)],
                    ],
                    'headings' => [$locale => $notification->titleFor($locale)],
                    'contents' => [$locale => $notification->bodyFor($locale)],
                    'data' => [
                        'notification_id' => $notification->id,
                        'type' => $notification->type,
                        'url' => $targetUrl,
                        'created_at' => $notification->created_at?->toIso8601String(),
                        'target_screen' => $this->targetScreen($targetUrl),
                        'target_id' => $data['order_id'] ?? $data['chat_id'] ?? null,
                        'conversation_id' => $data['chat_id'] ?? null,
                        'message_id' => $data['message_id'] ?? null,
                    ],
                    'url' => $targetUrl,
                    // Android displays this at high priority. The chosen
                    // OneSignal Android channel controls the audible sound.
                    'priority' => 10,
                    'android_channel_id' => $channelId,
                    // Message bursts are grouped by their conversation while
                    // order and general notices stay independently visible.
                    'collapse_id' => $this->collapseId($notification, $data),
                ]);

            if ($response->failed()) {
                Log::warning('OneSignal delivery failed.', [
                    'notification_id' => $notification->id,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $exception) {
            Log::warning('OneSignal delivery could not be processed.', [
                'notification_id' => $notification->id,
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    public function externalId(int $userId): string
    {
        return 'almunjaz-user-'.$userId;
    }

    private function targetUrl(Notification $notification): string
    {
        $data = is_array($notification->data) ? $notification->data : [];

        if (is_string($data['url'] ?? null) && str_starts_with($data['url'], '/app/')) {
            return $data['url'];
        }

        if ($chatId = filter_var($data['chat_id'] ?? null, FILTER_VALIDATE_INT)) {
            return '/app/chats/'.$chatId;
        }

        if ($orderId = filter_var($data['order_id'] ?? null, FILTER_VALIDATE_INT)) {
            return '/app/orders?open='.$orderId.'&list=1';
        }

        return '/app/notifications?open='.$notification->id;
    }

    private function channelId(Notification $notification): ?string
    {
        $channels = config('onesignal.android_channels', []);
        $bucket = in_array($notification->type, ['chat', 'message'], true)
            ? 'messages'
            : ($notification->type === 'order' ? 'orders' : 'general');

        return ($channels[$bucket] ?? null) ?: config('onesignal.android_channel_id') ?: null;
    }

    private function collapseId(Notification $notification, array $data): string
    {
        if ($chatId = filter_var($data['chat_id'] ?? null, FILTER_VALIDATE_INT)) {
            return 'chat-'.$chatId;
        }

        return 'notification-'.$notification->id;
    }

    private function targetScreen(string $targetUrl): string
    {
        return str_starts_with($targetUrl, '/app/chats/')
            ? 'chat'
            : (str_starts_with($targetUrl, '/app/orders') ? 'order' : 'notifications');
    }
}
