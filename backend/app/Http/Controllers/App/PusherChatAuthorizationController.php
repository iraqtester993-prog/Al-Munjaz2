<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\CourierOrderAccess;
use Illuminate\Http\Request;

class PusherChatAuthorizationController extends Controller
{
    public function __invoke(Request $request, CourierOrderAccess $orders)
    {
        $data = $request->validate([
            'socket_id' => ['required', 'string', 'regex:/^\\d+\\.\\d+$/'],
            'channel_name' => ['required', 'string', 'regex:/^private-(?:chat|user)[.-]\\d+$/'],
        ]);

        if (preg_match('/^private-user[.-](\\d+)$/', $data['channel_name'], $userChannel)) {
            abort_unless((int) $userChannel[1] === (int) $request->user()->id, 403);

            return $this->signedResponse($data['socket_id'], $data['channel_name']);
        }

        preg_match('/^private-chat[.-](\\d+)$/', $data['channel_name'], $chatChannel);
        $chatId = (int) ($chatChannel[1] ?? 0);
        $chat = Chat::withoutGlobalScope(TenantScope::class)->with('order:id')->findOrFail($chatId);
        abort_unless($this->canAccess($request->user(), $chat, $orders), 403);

        return $this->signedResponse($data['socket_id'], $data['channel_name']);
    }

    private function signedResponse(string $socketId, string $channel): \Illuminate\Http\JsonResponse
    {
        $config = config('pusher-chat');
        abort_unless($config['key'] && $config['secret'], 503);
        $signature = hash_hmac('sha256', $socketId.':'.$channel, $config['secret']);

        return response()->json(['auth' => $config['key'].':'.$signature]);
    }

    private function canAccess(User $user, Chat $chat, CourierOrderAccess $orders): bool
    {
        if ((int) $chat->user_id === (int) $user->id || $user->isAdmin()) {
            return true;
        }

        if ($chat->counterparty_type !== 'order_chat' || (int) $chat->counterparty_id !== (int) $user->id || ! $chat->order) {
            return false;
        }

        return $orders->assigned($user)->whereKey($chat->order->id)->exists()
            || $orders->available($user)->whereKey($chat->order->id)->exists();
    }
}
