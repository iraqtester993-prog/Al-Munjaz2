<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Notification;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\CourierOrderAccess;
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
            ->with([
                'user:id,name,role,shop_name',
                'counterparty:id,name,role,shop_name',
                'order:id,track_no,courier_id,merchant_id,pickup_courier_id,delivery_courier_id',
            ])
            ->latest('last_at');

        return response()->json(['data' => $chats->paginate(min($request->integer('per_page', 30), 100))->through(fn (Chat $chat) => $this->data($chat, $user))]);
    }

    public function show(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeChat($request, $chat);
        $this->markRead($chat, $request->user());

        return response()->json(['data' => $this->data($chat->load([
            'messages.sender:id,name',
            'user:id,name,role,shop_name',
            'counterparty:id,name,role,shop_name',
            'order:id,track_no,courier_id,merchant_id,pickup_courier_id,delivery_courier_id',
        ]), $request->user()) + ['messages' => $chat->messages->map(fn (ChatMessage $message) => ['id' => $message->id, 'text' => $message->text, 'sender' => $message->sender?->name, 'sender_id' => $message->sender_id, 'created_at' => $message->created_at?->toISOString()])]]);
    }

    /** A lightweight incremental alternative to reloading the whole thread. */
    public function messages(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeChat($request, $chat);
        $this->markRead($chat, $request->user());
        $data = $request->validate(['after_id' => ['nullable', 'integer', 'min:0']]);

        $messages = $chat->messages()
            ->when(($data['after_id'] ?? 0) > 0, fn (Builder $messages) => $messages->where('id', '>', $data['after_id']))
            ->orderBy('id')
            ->get()
            ->map(fn (ChatMessage $message) => [
                'id' => $message->id,
                'text' => $message->text,
                'sender_id' => $message->sender_id,
                'created_at' => $message->created_at?->toISOString(),
            ])
            ->values();

        return response()->json([
            'data' => [
                'messages' => $messages,
                'last_id' => (int) (data_get($messages->last(), 'id') ?? ($data['after_id'] ?? 0)),
            ],
        ], 200, ['Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0']);
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
            $this->notifyMessageRecipients($chat, $user);
        });

        return response()->json(['data' => $this->data($chat->fresh()->load([
            'user:id,name,role,shop_name',
            'counterparty:id,name,role,shop_name',
            'order:id,track_no,courier_id,merchant_id,pickup_courier_id,delivery_courier_id',
        ]), $user)], 201);
    }

    private function authorizeChat(Request $request, Chat $chat): void
    {
        $user = $request->user();

        if ($this->isOperationsUser($user) || $chat->user_id === $user->id) {
            return;
        }

        $isAssignedCourier = $chat->counterparty_type === 'order_chat'
            && $chat->counterparty_id === $user->id
            && $chat->order
            && app(CourierOrderAccess::class)->assigned($user)->whereKey($chat->order->id)->exists();

        abort_unless($isAssignedCourier, 403);
    }

    private function data(Chat $chat, User $viewer): array
    {
        $counterparty = $this->counterpartyForViewer($chat, $viewer);
        $counterpartyName = $counterparty?->name;
        $trackNo = $chat->order?->track_no;

        if ($chat->counterparty_type === 'order_chat') {
            $title = 'محادثة مع '.($counterpartyName ?: 'الطرف الآخر').($trackNo ? ' — '.$trackNo : '');
        } elseif ($chat->counterparty_type === 'order_support') {
            $title = 'شكوى / تأخر — '.($counterpartyName ?: 'مندوب غير مكلّف').($trackNo ? ' — '.$trackNo : '');
        } else {
            $title = $chat->title_ar ?: $chat->user?->name ?: 'محادثة';
        }

        return [
            'id' => $chat->id,
            'title' => $title,
            'counterparty_type' => $chat->counterparty_type,
            'counterparty_name' => $counterpartyName,
            'counterparty_role' => $counterparty?->role,
            'order_id' => $chat->order_id,
            'track_no' => $trackNo,
            'last_message' => $chat->last_message,
            'last_at' => $chat->last_at?->toISOString(),
            'unread' => $chat->unread,
            'user' => $chat->user ? ['id' => $chat->user->id, 'name' => $chat->user->name] : null,
        ];
    }

    private function counterpartyForViewer(Chat $chat, User $viewer): ?User
    {
        if ($chat->counterparty_type === 'order_chat') {
            if ((int) $chat->user_id === (int) $viewer->id) {
                return $chat->counterparty;
            }

            if ((int) $chat->counterparty_id === (int) $viewer->id) {
                return $chat->user;
            }
        }

        if ($chat->counterparty_type === 'order_support') {
            return $chat->counterparty;
        }

        return null;
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

        $assignedOrderIds = $user->isCourierRole()
            ? app(CourierOrderAccess::class)->assigned($user)->select('id')
            : null;

        return $query->where(function (Builder $chats) use ($user, $assignedOrderIds): void {
            $chats->where('user_id', $user->id)
                ->orWhere(function (Builder $directChats) use ($user, $assignedOrderIds): void {
                    $directChats
                        ->where('counterparty_type', 'order_chat')
                        ->where('counterparty_id', $user->id);

                    if ($assignedOrderIds) {
                        $directChats->whereIn('order_id', $assignedOrderIds);
                    } else {
                        $directChats->whereRaw('1 = 0');
                    }
                });
        });
    }

    private function notifyMessageRecipients(Chat $chat, User $sender): void
    {
        $recipientIds = $chat->counterparty_type === 'order_chat'
            ? [$chat->user_id, $chat->counterparty_id]
            : ($sender->isAdmin() && $chat->user_id ? [$chat->user_id] : []);

        User::query()
            ->whereIn('id', collect($recipientIds)
                ->filter(fn ($id) => $id && (int) $id !== (int) $sender->id)
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->all())
            ->where('status', 'active')
            ->get()
            ->each(function (User $recipient) use ($chat): void {
                Notification::create([
                    'tenant_id' => $recipient->tenant_id,
                    'user_id' => $recipient->id,
                    'type' => 'chat',
                    'title_ar' => 'رسالة جديدة',
                    'title_en' => 'New message',
                    'title_ku' => 'نامەیەکی نوێ',
                    'body_ar' => 'لديك رسالة جديدة في محادثة الطلب.',
                    'body_en' => 'You have a new message in an order conversation.',
                    'body_ku' => 'نامەیەکی نوێت لە گفتوگۆی داواکارییەکەدا هەیە.',
                    'data' => ['url' => '/app/chats/'.$chat->id, 'chat_id' => $chat->id],
                ]);
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
