<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $chats = $this->visibleChats($user)
            ->with(['user:id,name', 'order:id,track_no,courier_id,merchant_id'])
            ->latest('last_at');

        return response()->json(['data' => $chats->paginate(min($request->integer('per_page', 30), 100))->through(fn (Chat $chat) => $this->data($chat))]);
    }

    public function show(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeChat($request, $chat);
        $this->markRead($chat, $request->user());

        return response()->json(['data' => $this->data($chat->load(['messages.sender:id,name'])) + ['messages' => $chat->messages->map(fn (ChatMessage $message) => ['id' => $message->id, 'text' => $message->text, 'sender' => $message->sender?->name, 'sender_id' => $message->sender_id, 'created_at' => $message->created_at?->toISOString()])]]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['text' => ['required', 'string', 'max:3000'], 'chat_id' => ['nullable', 'integer', 'exists:chats,id']]);
        $chat = isset($data['chat_id'])
            ? Chat::withoutGlobalScope(TenantScope::class)->findOrFail($data['chat_id'])
            : Chat::firstOrCreate(['tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'counterparty_type' => 'support'], ['title_ar' => 'دعم المنجز']);
        $this->authorizeChat($request, $chat);

        DB::transaction(function () use ($chat, $data, $user) {
            ChatMessage::create(['chat_id' => $chat->id, 'sender_id' => $user->id, 'text' => $data['text'], 'created_at' => now()]);
            $chat->update(['last_message' => $data['text'], 'last_at' => now(), 'unread' => $chat->user_id === $user->id ? $chat->unread + 1 : $chat->unread]);
            $this->markRead($chat, $user);
        });

        return response()->json(['data' => $this->data($chat->fresh())], 201);
    }

    private function authorizeChat(Request $request, Chat $chat): void
    {
        $user = $request->user();

        if ($this->isOperationsUser($user) || $chat->user_id === $user->id) {
            return;
        }

        $isAssignedCourier = $chat->counterparty_type === 'order_chat'
            && $chat->counterparty_id === $user->id
            && $chat->order?->courier_id === $user->id;

        abort_unless($isAssignedCourier, 403);
    }

    private function data(Chat $chat): array
    {
        return [
            'id' => $chat->id,
            'title' => $chat->title_ar ?: $chat->user?->name ?: 'محادثة',
            'counterparty_type' => $chat->counterparty_type,
            'order_id' => $chat->order_id,
            'track_no' => $chat->order?->track_no,
            'last_message' => $chat->last_message,
            'last_at' => $chat->last_at?->toISOString(),
            'unread' => $chat->unread,
            'user' => $chat->user ? ['id' => $chat->user->id, 'name' => $chat->user->name] : null,
        ];
    }

    /**
     * API consumers use the same explicit cross-tenant rule as the web PWA:
     * the order owner sees the chat by `user_id`; its currently assigned
     * courier sees it as the direct counterparty.
     */
    private function visibleChats(User $user): Builder
    {
        $query = Chat::withoutGlobalScope(TenantScope::class);

        if ($this->isOperationsUser($user)) {
            return $query;
        }

        return $query->where(function (Builder $chats) use ($user): void {
            $chats->where('user_id', $user->id)
                ->orWhere(function (Builder $directChats) use ($user): void {
                    $directChats
                        ->where('counterparty_type', 'order_chat')
                        ->where('counterparty_id', $user->id)
                        ->whereHas('order', fn (Builder $orders) => $orders->where('courier_id', $user->id));
                });
        });
    }

    private function markRead(Chat $chat, User $user): void
    {
        $key = $this->readColumnFor($chat, $user);
        $chat->forceFill([$key => now(), 'unread' => $this->isOperationsUser($user) ? 0 : $chat->unread])->save();
    }

    private function readColumnFor(Chat $chat, User $user): string
    {
        if ($this->isOperationsUser($user)) {
            return 'admin_read_at';
        }

        return $chat->counterparty_type === 'order_chat' && $chat->counterparty_id === $user->id
            ? 'counterparty_read_at'
            : 'user_read_at';
    }

    private function isOperationsUser(User $user): bool
    {
        return in_array($user->role, ['admin', 'support'], true);
    }
}
