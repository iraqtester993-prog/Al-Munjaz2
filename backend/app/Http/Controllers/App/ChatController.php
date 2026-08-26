<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $chats = $this->chatsFor($user)
            ->with('order:id,track_no,courier_id,merchant_id')
            ->orderByDesc('last_at')
            ->get()
            ->map(fn (Chat $chat) => [
                'id' => $chat->id,
                'title_ar' => $chat->title_ar,
                'title_en' => $chat->title_en,
                'counterparty_type' => $chat->counterparty_type,
                'order_id' => $chat->order_id,
                'track_no' => $chat->order?->track_no,
                'last_message' => $chat->last_message,
                'last_at' => $chat->last_at?->diffForHumans(),
                'unread' => $this->unreadFor($chat, $user),
            ]);

        return Inertia::render('Mobile/Chats', ['chats' => $chats]);
    }

    public function show(Request $request, Chat $chat)
    {
        $this->ensureParticipant($request, $chat);
        $this->markRead($chat, $request->user());

        $messages = $this->messagesFor($chat, $request->user());

        return Inertia::render('Mobile/ChatThread', [
            'chat' => [
                'id' => $chat->id,
                'title_ar' => $chat->title_ar,
                'title_en' => $chat->title_en,
                'counterparty_type' => $chat->counterparty_type,
                'order_id' => $chat->order_id,
                'track_no' => $chat->order?->track_no,
            ],
            'messages' => $messages,
        ]);
    }

    public function send(Request $request, Chat $chat)
    {
        $request->validate(['text' => ['required', 'string', 'max:1000']]);

        $this->ensureParticipant($request, $chat);

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'sender_id' => $request->user()->id,
            'text' => $request->input('text'),
            'created_at' => now(),
        ]);

        $chat->update([
            'last_message' => $request->input('text'),
            'last_at' => now(),
        ]);
        $this->markRead($chat, $request->user());

        return response()->json([
            'id' => $message->id,
            'text' => $message->text,
            'from_me' => true,
            'time' => $message->created_at->format('H:i'),
        ]);
    }

    /**
     * Lightweight polling endpoint.  It deliberately returns JSON only, so
     * an open conversation updates without re-rendering the entire Inertia
     * page or moving the user out of the current scroll position.
     */
    public function messages(Request $request, Chat $chat)
    {
        $this->ensureParticipant($request, $chat);
        $this->markRead($chat, $request->user());

        return response()->json([
            'messages' => $this->messagesFor($chat, $request->user()),
            'unread' => $this->unreadFor($chat, $request->user()),
        ]);
    }

    public function open(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
        ]);

        if (! empty($data['order_id'])) {
            $order = Order::withoutGlobalScope(TenantScope::class)->findOrFail($data['order_id']);
            $this->ensureOrderChatAccess($order, $user);

            // Once an order has an assigned courier, the merchant and that
            // courier share one real conversation.  It is deliberately tied
            // to the order instead of to whichever party opened it first.
            if ($order->courier_id) {
                $merchantId = $this->merchantIdForOrder($order, $user);
                abort_unless($merchantId, 422, 'لا يوجد حساب تاجر صالح لهذه الطلبية.');

                $chat = Chat::withoutGlobalScope(TenantScope::class)->firstOrCreate(
                    [
                        'tenant_id' => $order->tenant_id,
                        'counterparty_type' => 'order_chat',
                        'order_id' => $order->id,
                    ],
                    [
                        'user_id' => $merchantId,
                        'counterparty_id' => $order->courier_id,
                        'title_ar' => 'محادثة الطلب — '.$order->track_no,
                        'title_en' => 'Order chat — '.$order->track_no,
                        'last_message' => '',
                        'last_at' => now(),
                    ]
                );

                // A reassignment must revoke the previous courier's access
                // and make the new assigned courier the conversation party.
                $chat->fill([
                    'user_id' => $merchantId,
                    'counterparty_id' => $order->courier_id,
                    'title_ar' => 'محادثة الطلب — '.$order->track_no,
                    'title_en' => 'Order chat — '.$order->track_no,
                ]);

                if ($chat->isDirty()) {
                    $chat->save();
                }

                return redirect()->route('app.chats.show', $chat);
            }

            // Before an assignment there is no second operational party.
            // Keep the historical order-support path for a merchant who
            // needs to ask the operations team about that order.
            abort_unless($user->role === 'merchant', 403);

            $chat = Chat::withoutGlobalScope(TenantScope::class)->firstOrCreate(
                [
                    'tenant_id' => $order->tenant_id,
                    'user_id' => $user->id,
                    'counterparty_type' => 'order_support',
                    'order_id' => $order->id,
                ],
                [
                    'title_ar' => 'شكوى / تأخر — '.$order->track_no,
                    'title_en' => 'Order support — '.$order->track_no,
                    'last_message' => '',
                    'last_at' => now(),
                ]
            );

            return redirect()->route('app.chats.show', $chat);
        }

        $chat = Chat::firstOrCreate(
            ['tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'counterparty_type' => 'support'],
            [
                'title_ar' => 'الدعم الفني',
                'title_en' => 'Support',
                'last_message' => '',
                'last_at' => now(),
            ]
        );

        return redirect()->route('app.chats.show', $chat);
    }

    public function adminIndex(Request $request)
    {
        $chats = Chat::withoutGlobalScope(TenantScope::class)
            ->with('user:id,name,phone')
            ->orderByDesc('last_at')
            ->get()
            ->map(fn (Chat $chat) => [
                'id' => $chat->id,
                'title_ar' => $chat->title_ar,
                'title_en' => $chat->title_en,
                'last_message' => $chat->last_message,
                'last_at' => $chat->last_at?->diffForHumans(),
                'unread' => $this->unreadFor($chat, $request->user()),
                'user' => $chat->user ? ['name' => $chat->user->name, 'phone' => $chat->user->phone] : null,
                'counterparty_type' => $chat->counterparty_type,
            ]);

        return Inertia::render('Admin/Chat', ['chats' => $chats]);
    }

    public function adminShow(Request $request, Chat $chat)
    {
        $this->markRead($chat, $request->user());

        $messages = $this->messagesFor($chat, $request->user());

        return Inertia::render('Admin/Chat', [
            'chats' => Chat::withoutGlobalScope(TenantScope::class)->with('user:id,name,phone')->orderByDesc('last_at')->get()->map(fn (Chat $c) => [
                'id' => $c->id,
                'title_ar' => $c->title_ar,
                'title_en' => $c->title_en,
                'last_message' => $c->last_message,
                'last_at' => $c->last_at?->diffForHumans(),
                'unread' => $this->unreadFor($c, $request->user()),
                'user' => $c->user ? ['name' => $c->user->name, 'phone' => $c->user->phone] : null,
                'counterparty_type' => $c->counterparty_type,
            ]),
            'activeChat' => [
                'id' => $chat->id,
                'title_ar' => $chat->title_ar,
                'title_en' => $chat->title_en,
                'user' => $chat->user ? ['name' => $chat->user->name, 'phone' => $chat->user->phone] : null,
            ],
            'messages' => $messages,
        ]);
    }

    public function adminSend(Request $request, Chat $chat)
    {
        $request->validate(['text' => ['required', 'string', 'max:1000']]);

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'sender_id' => $request->user()->id,
            'text' => $request->input('text'),
            'created_at' => now(),
        ]);

        $chat->update([
            'last_message' => $request->input('text'),
            'last_at' => now(),
        ]);
        $this->markRead($chat, $request->user());

        return response()->json([
            'id' => $message->id,
            'text' => $message->text,
            'from_me' => true,
            'time' => $message->created_at->format('H:i'),
        ]);
    }

    public function adminMessages(Request $request, Chat $chat)
    {
        $this->markRead($chat, $request->user());

        return response()->json([
            'messages' => $this->messagesFor($chat, $request->user()),
            'unread' => $this->unreadFor($chat, $request->user()),
        ]);
    }

    private function ensureParticipant(Request $request, Chat $chat): void
    {
        $user = $request->user();

        if ($user->isAdmin() || $chat->user_id === $user->id) {
            return;
        }

        $isAssignedCourier = $chat->counterparty_type === 'order_chat'
            && $chat->counterparty_id === $user->id
            && $chat->order?->courier_id === $user->id;

        abort_unless($isAssignedCourier, 403);
    }

    private function markRead(Chat $chat, $user): void
    {
        $key = $this->readColumnFor($chat, $user);
        $chat->forceFill([$key => now(), 'unread' => $user->isAdmin() ? 0 : $chat->unread])->save();
    }

    private function unreadFor(Chat $chat, $user): int
    {
        $readAt = $chat->{$this->readColumnFor($chat, $user)};

        return $chat->messages()
            ->where('sender_id', '!=', $user->id)
            ->when($readAt, fn ($query) => $query->where('created_at', '>', $readAt))
            ->count();
    }

    private function messagesFor(Chat $chat, $user)
    {
        return $chat->messages()->orderBy('id')->get()->map(fn (ChatMessage $message) => [
            'id' => $message->id,
            'sender_id' => $message->sender_id,
            'from_me' => $message->sender_id === $user->id,
            'text' => $message->text,
            'time' => $message->created_at->format('H:i'),
        ])->values();
    }

    /**
     * Return only conversations that the authenticated account is entitled
     * to see.  A courier's tenant differs from the merchant tenant that owns
     * its orders, so this intentionally removes TenantScope and replaces it
     * with explicit participant constraints.
     */
    private function chatsFor(User $user): Builder
    {
        $query = Chat::withoutGlobalScope(TenantScope::class);

        if ($user->isAdmin()) {
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

    private function ensureOrderChatAccess(Order $order, User $user): void
    {
        if ($user->role === 'merchant') {
            abort_unless((int) $order->tenant_id === (int) $user->tenant_id, 403);

            return;
        }

        abort_unless($user->role === 'courier' && (int) $order->courier_id === (int) $user->id, 403);
    }

    private function merchantIdForOrder(Order $order, User $actor): ?int
    {
        // Preserve the actual order owner when it is known.  A second
        // merchant account in the same tenant must not silently take over an
        // already-established direct conversation just by opening it.
        foreach ([$order->merchant_id, $order->created_by] as $candidateId) {
            if (! $candidateId) {
                continue;
            }

            $merchantId = User::query()
                ->whereKey($candidateId)
                ->where('tenant_id', $order->tenant_id)
                ->whereIn('role', ['merchant', 'owner'])
                ->value('id');

            if ($merchantId) {
                return (int) $merchantId;
            }
        }

        if ($actor->role === 'merchant' && (int) $actor->tenant_id === (int) $order->tenant_id) {
            return $actor->id;
        }

        $merchantId = User::query()
            ->where('tenant_id', $order->tenant_id)
            ->whereIn('role', ['merchant', 'owner'])
            ->orderBy('id')
            ->value('id');

        return $merchantId ? (int) $merchantId : null;
    }

    private function readColumnFor(Chat $chat, User $user): string
    {
        if ($user->isAdmin()) {
            return 'admin_read_at';
        }

        return $chat->counterparty_type === 'order_chat' && $chat->counterparty_id === $user->id
            ? 'counterparty_read_at'
            : 'user_read_at';
    }
}
