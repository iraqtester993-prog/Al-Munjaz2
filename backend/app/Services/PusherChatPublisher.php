<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;

/** Sends a metadata-only new-message signal through Pusher's HTTP API. */
class PusherChatPublisher
{
    public function publish(ChatMessage $message, array $recipientUserIds = []): void
    {
        $channels = array_merge(['private-chat.'.$message->chat_id], array_map(
            fn (int $userId) => 'private-user.'.$userId,
            array_values(array_unique(array_filter($recipientUserIds))),
        ));

        $this->publishEvent('chat.message', $channels, [
            'chat_id' => (int) $message->chat_id,
            'message_id' => (int) $message->id,
        ]);
    }

    public function publishNotification(Notification $notification): void
    {
        if (! $notification->user_id) return;

        $this->publishEvent('app.notification', ['private-user.'.$notification->user_id], [
            'notification_id' => (int) $notification->id,
            'type' => $notification->type,
        ]);
    }

    private function publishEvent(string $event, array $channels, array $data): void
    {
        $config = config('pusher-chat');

        if (! $config['enabled'] || ! $config['app_id'] || ! $config['key'] || ! $config['secret']) {
            return;
        }

        if (! function_exists('curl_init')) {
            Log::warning('Pusher chat event was skipped because cURL is unavailable.');

            return;
        }

        $path = '/apps/'.$config['app_id'].'/events';
        $payload = json_encode([
            'name' => $event,
            'channels' => array_values(array_unique($channels)),
            'data' => json_encode($data, JSON_THROW_ON_ERROR),
        ], JSON_THROW_ON_ERROR);

        $params = [
            'auth_key' => $config['key'],
            'auth_timestamp' => (string) now()->timestamp,
            'auth_version' => '1.0',
            'body_md5' => md5($payload),
        ];
        ksort($params);
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $signature = hash_hmac('sha256', "POST\n{$path}\n{$query}", $config['secret']);
        $url = 'https://api-'.$config['cluster'].'.pusher.com'.$path.'?'.$query.'&auth_signature='.$signature;

        $request = curl_init($url);
        curl_setopt_array($request, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($request);
        $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        $error = curl_error($request);
        curl_close($request);

        if ($status < 200 || $status >= 300) {
            Log::warning('Pusher chat event was not delivered.', [
                'event' => $event,
                'status' => $status,
                'error' => $error ?: null,
            ]);
        }
    }
}
