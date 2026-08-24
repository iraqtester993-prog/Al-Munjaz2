<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\ChatMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $chats = Chat::query()->with('user:id,name')->latest('last_at');
        if (!in_array($user->role, ['admin', 'support'], true)) $chats->where('user_id', $user->id);

        return response()->json(['data' => $chats->paginate(min($request->integer('per_page', 30), 100))->through(fn (Chat $chat) => $this->data($chat))]);
    }

    public function show(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeChat($request, $chat);
        Chat::whereKey($chat->id)->update(['unread' => 0]);
        return response()->json(['data' => $this->data($chat->load(['messages.sender:id,name'])) + ['messages' => $chat->messages->map(fn (ChatMessage $message) => ['id' => $message->id, 'text' => $message->text, 'sender' => $message->sender?->name, 'sender_id' => $message->sender_id, 'created_at' => $message->created_at?->toISOString()])]]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['text' => ['required', 'string', 'max:3000'], 'chat_id' => ['nullable', 'integer', 'exists:chats,id']]);
        $chat = isset($data['chat_id']) ? Chat::findOrFail($data['chat_id']) : Chat::firstOrCreate(['tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'counterparty_type' => 'support'], ['title_ar' => 'دعم المنجز']);
        $this->authorizeChat($request, $chat);

        DB::transaction(function () use ($chat, $data, $user) {
            ChatMessage::create(['chat_id' => $chat->id, 'sender_id' => $user->id, 'text' => $data['text'], 'created_at' => now()]);
            $chat->update(['last_message' => $data['text'], 'last_at' => now(), 'unread' => $chat->user_id === $user->id ? $chat->unread + 1 : $chat->unread]);
        });
        return response()->json(['data' => $this->data($chat->fresh())], 201);
    }

    private function authorizeChat(Request $request, Chat $chat): void
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['admin', 'support'], true) || $chat->user_id === $user->id, 403);
    }

    private function data(Chat $chat): array
    {
        return ['id' => $chat->id, 'title' => $chat->title_ar ?: $chat->user?->name ?: 'محادثة', 'last_message' => $chat->last_message, 'last_at' => $chat->last_at?->toISOString(), 'unread' => $chat->unread, 'user' => $chat->user ? ['id' => $chat->user->id, 'name' => $chat->user->name] : null];
    }
}
