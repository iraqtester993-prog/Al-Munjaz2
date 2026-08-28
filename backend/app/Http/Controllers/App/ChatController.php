<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\CourierOrderAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $chats = $this->chatsFor($user)
            ->with([
                'user:id,name,role,shop_name',
                'counterparty:id,name,role,shop_name',
                'order:id,track_no,courier_id,merchant_id,pickup_courier_id,delivery_courier_id',
            ])
            ->orderByDesc('last_at')
            ->get()
            ->map(fn (Chat $chat) => $this->mobileChatPayload($chat, $user, true));

        return Inertia::render('Mobile/Chats', ['chats' => $chats]);
    }

    public function show(Request $request, Chat $chat)
    {
        $this->ensureParticipant($request, $chat);
        $this->markRead($chat, $request->user());

        $messages = $this->messagesFor($chat, $request->user());

        $chat->loadMissing([
            'user:id,name,role,shop_name',
            'counterparty:id,name,role,shop_name',
            'order:id,track_no,courier_id,merchant_id,pickup_courier_id,delivery_courier_id',
        ]);

        return Inertia::render('Mobile/ChatThread', [
            'chat' => $this->mobileChatPayload($chat, $request->user()),
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
        $this->notifyMessageRecipients($chat, $request->user());

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

        $data = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
        ]);
        $messages = $this->messagesFor($chat, $request->user(), $data['after_id'] ?? null);

        return response()->json([
            'messages' => $messages,
            // Use the last message actually returned, not a new row that
            // might have arrived between the incremental query and this JSON
            // response. Advancing past an unseen ID would silently skip it.
            'last_id' => (int) (data_get($messages->last(), 'id') ?? ($data['after_id'] ?? 0)),
            'unread' => $this->unreadFor($chat, $request->user()),
        ], 200, ['Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0']);
    }

    public function open(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'complaint' => ['nullable', 'boolean'],
        ]);

        if (! empty($data['order_id'])) {
            $order = Order::withoutGlobalScope(TenantScope::class)->findOrFail($data['order_id']);
            $this->ensureOrderChatAccess($order, $user);

            // A complaint must always reach operations, even when the order
            // already has a courier. The merchant-to-courier conversation is
            // retained separately so operational support has a clear audited
            // thread with the order context instead of an ambiguous reply.
            if ($request->boolean('complaint')) {
                abort_unless($user->role === 'merchant', 403);

                $chat = Chat::withoutGlobalScope(TenantScope::class)->firstOrCreate(
                    [
                        'tenant_id' => $order->tenant_id,
                        'user_id' => $user->id,
                        'counterparty_type' => 'order_support',
                        'order_id' => $order->id,
                    ],
                    $this->complaintSnapshotAttributes($order) + [
                        'last_message' => '',
                        'last_at' => now(),
                    ]
                );

                // Legacy complaints did not retain which courier the issue
                // referred to. Attach a snapshot the first time one is
                // opened so the dashboard has a stable, auditable label.
                $this->completeLegacyComplaintSnapshot($chat, $order);

                return redirect()->route('app.chats.show', $chat);
            }

            // An order may have a pickup courier and a delivery courier. Each
            // one receives an isolated direct conversation with the merchant;
            // a courier always opens their own conversation, while the
            // merchant opens the current operational courier by default.
            if ($courierId = $this->directCourierIdFor($order, $user)) {
                $merchantId = $this->merchantIdForOrder($order, $user);
                abort_unless($merchantId, 422, 'لا يوجد حساب تاجر صالح لهذه الطلبية.');

                $chat = Chat::withoutGlobalScope(TenantScope::class)->firstOrCreate(
                    [
                        'tenant_id' => $order->tenant_id,
                        'counterparty_type' => 'order_chat',
                        'order_id' => $order->id,
                        'counterparty_id' => $courierId,
                    ],
                    [
                        'user_id' => $merchantId,
                        'title_ar' => 'محادثة الطلب — '.$order->track_no,
                        'title_en' => 'Order chat — '.$order->track_no,
                        'last_message' => '',
                        'last_at' => now(),
                    ]
                );

                // Keep the merchant identity and title current without
                // changing the counterparty of an existing chat. Changing a
                // counterparty would expose an old courier's history to a new
                // courier; reassignment creates a separate direct chat.
                $chat->fill([
                    'user_id' => $merchantId,
                    'title_ar' => 'محادثة الطلب — '.$order->track_no,
                    'title_en' => 'Order chat — '.$order->track_no,
                ]);

                if ($chat->isDirty()) {
                    $chat->save();
                }

                return redirect()->route('app.chats.show', $chat);
            }

            // A courier is allowed to ask the merchant about an available
            // offer before accepting it. The conversation remains scoped to
            // this exact order and courier, so it never exposes another
            // courier's messages or a tenant-wide support thread.
            if ($user->isCourierRole()) {
                $merchantId = $this->merchantIdForOrder($order, $user);
                abort_unless($merchantId, 422, 'لا يوجد حساب تاجر صالح لهذه الطلبية.');

                $chat = Chat::withoutGlobalScope(TenantScope::class)->firstOrCreate(
                    [
                        'tenant_id' => $order->tenant_id,
                        'counterparty_type' => 'order_chat',
                        'order_id' => $order->id,
                        'counterparty_id' => $user->id,
                    ],
                    [
                        'user_id' => $merchantId,
                        'title_ar' => 'محادثة الطلب — '.$order->track_no,
                        'title_en' => 'Order chat — '.$order->track_no,
                        'last_message' => '',
                        'last_at' => now(),
                    ]
                );

                return redirect()->route('app.chats.show', $chat);
            }

            // A merchant without a courier keeps the historical support path.
            abort_unless($user->role === 'merchant', 403);

            $chat = Chat::withoutGlobalScope(TenantScope::class)->firstOrCreate(
                [
                    'tenant_id' => $order->tenant_id,
                    'user_id' => $user->id,
                    'counterparty_type' => 'order_support',
                    'order_id' => $order->id,
                ],
                $this->complaintSnapshotAttributes($order) + [
                    'last_message' => '',
                    'last_at' => now(),
                ]
            );

            $this->completeLegacyComplaintSnapshot($chat, $order);

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
            ->with([
                'user:id,name,phone,role,shop_name',
                'counterparty:id,name,phone,role,shop_name',
                'order:id,track_no,customer_name_ar,customer_name_en,phone,address_ar,address_en,status',
            ])
            ->orderByDesc('last_at')
            ->get()
            ->map(fn (Chat $chat) => $this->adminChatPayload($chat, $request->user()));

        return Inertia::render('Admin/Chat', ['chats' => $chats]);
    }

    public function adminShow(Request $request, Chat $chat)
    {
        $this->markRead($chat, $request->user());

        $messages = $this->messagesFor($chat, $request->user());

        $chat->loadMissing([
            'user:id,name,phone,role,shop_name',
            'counterparty:id,name,phone,role,shop_name',
            'order:id,track_no,customer_name_ar,customer_name_en,phone,address_ar,address_en,status',
        ]);

        return Inertia::render('Admin/Chat', [
            'chats' => Chat::withoutGlobalScope(TenantScope::class)->with([
                'user:id,name,phone,role,shop_name',
                'counterparty:id,name,phone,role,shop_name',
                'order:id,track_no,customer_name_ar,customer_name_en,phone,address_ar,address_en,status',
            ])->orderByDesc('last_at')->get()->map(fn (Chat $c) => $this->adminChatPayload($c, $request->user())),
            'activeChat' => $this->adminChatPayload($chat, $request->user()),
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
        $this->notifyMessageRecipients($chat, $request->user());

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

        $data = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
        ]);
        $messages = $this->messagesFor($chat, $request->user(), $data['after_id'] ?? null);

        return response()->json([
            'messages' => $messages,
            'last_id' => (int) (data_get($messages->last(), 'id') ?? ($data['after_id'] ?? 0)),
            'unread' => $this->unreadFor($chat, $request->user()),
        ], 200, ['Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0']);
    }

    private function ensureParticipant(Request $request, Chat $chat): void
    {
        $user = $request->user();

        if ($user->isAdmin() || $chat->user_id === $user->id) {
            return;
        }

        $isAssignedCourier = $chat->counterparty_type === 'order_chat'
            && $chat->counterparty_id === $user->id
            && $chat->order
            && (
                app(CourierOrderAccess::class)->assigned($user)->whereKey($chat->order->id)->exists()
                || app(CourierOrderAccess::class)->available($user)->whereKey($chat->order->id)->exists()
            );

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

    private function messagesFor(Chat $chat, $user, ?int $afterId = null)
    {
        return $chat->messages()
            ->when($afterId !== null && $afterId > 0, fn (Builder $messages) => $messages->where('id', '>', $afterId))
            ->orderBy('id')
            ->get()
            ->map(fn (ChatMessage $message) => [
            'id' => $message->id,
            'sender_id' => $message->sender_id,
            'from_me' => $message->sender_id === $user->id,
            'text' => $message->text,
            'time' => $message->created_at->format('H:i'),
        ])->values();
    }

    /**
     * A direct order conversation has two real participants. A reply by one
     * participant reaches the other, while an administrator reply reaches
     * both mobile participants. Support conversations remain private to the
     * originating user and are never broadcast to every operator.
     */
    private function notifyMessageRecipients(Chat $chat, User $sender): void
    {
        $recipientIds = [];

        if ($chat->counterparty_type === 'order_chat') {
            $recipientIds = [$chat->user_id, $chat->counterparty_id];
        } elseif ($sender->isAdmin() && $chat->user_id && $chat->user_id !== $sender->id) {
            $recipientIds = [$chat->user_id];
        }

        $recipientIds = collect($recipientIds)
            ->filter(fn ($id) => $id && (int) $id !== (int) $sender->id)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($recipientIds->isEmpty()) {
            return;
        }

        User::query()
            ->whereIn('id', $recipientIds)
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

        $assignedOrderIds = $user->isCourierRole()
            ? app(CourierOrderAccess::class)->assigned($user)->select('id')
            : null;
        $availableOrderIds = $user->isCourierRole()
            ? app(CourierOrderAccess::class)->available($user)->select('id')
            : null;

        return $query->where(function (Builder $chats) use ($user, $assignedOrderIds, $availableOrderIds): void {
            $chats->where('user_id', $user->id)
                ->orWhere(function (Builder $directChats) use ($user, $assignedOrderIds, $availableOrderIds): void {
                    $directChats
                        ->where('counterparty_type', 'order_chat')
                        ->where('counterparty_id', $user->id);

                    if ($assignedOrderIds) {
                        $directChats->where(function (Builder $orderAccess) use ($assignedOrderIds, $availableOrderIds): void {
                            $orderAccess->whereIn('order_id', $assignedOrderIds);

                            if ($availableOrderIds) {
                                $orderAccess->orWhereIn('order_id', $availableOrderIds);
                            }
                        });
                    } else {
                        $directChats->whereRaw('1 = 0');
                    }
                });
        });
    }

    private function ensureOrderChatAccess(Order $order, User $user): void
    {
        if ($user->role === 'merchant') {
            abort_unless((int) $order->tenant_id === (int) $user->tenant_id, 403);

            return;
        }

        abort_unless($user->isCourierRole(), 403);

        $access = app(CourierOrderAccess::class);
        $canAccess = $access->assigned($user)->whereKey($order->id)->exists()
            || $access->available($user)->whereKey($order->id)->exists();

        abort_unless($canAccess, 403);
    }

    /** @return array<int, int> */
    private function assignedCourierIds(Order $order): array
    {
        return collect([
            $order->courier_id,
            $order->delivery_courier_id,
            $order->pickup_courier_id,
        ])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function directCourierIdFor(Order $order, User $actor): ?int
    {
        $courierIds = $this->assignedCourierIds($order);

        if ($actor->isCourierRole() && in_array((int) $actor->id, $courierIds, true)) {
            return (int) $actor->id;
        }

        // For an available offer the authenticated courier is the intended
        // counterparty for a pre-acceptance clarification conversation.
        if ($actor->isCourierRole() && $courierIds === []) {
            return (int) $actor->id;
        }

        // The merchant must be connected to the same operational courier
        // shown on the order card: pickup courier while waiting at the shop,
        // then delivery courier once the parcel is with a courier.  This
        // avoids a card/chat mismatch for specialised assignments.
        return $this->operationalCourierId($order);
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

    private function adminOrderContext(?Order $order): ?array
    {
        return $order ? [
            'id' => $order->id,
            'track_no' => $order->track_no,
            'customer_name' => $order->customer_name_ar ?: $order->customer_name_en,
            'phone' => $order->phone,
            'address' => $order->address_ar ?: $order->address_en,
            'status' => $order->status,
        ] : null;
    }

    /**
     * Resolve the courier whose work is currently visible on an order. This
     * is deliberately stage-aware so a merchant chats with the pickup
     * courier first and with the delivery courier after handover.
     */
    private function operationalCourierId(Order $order): ?int
    {
        return match ($order->status) {
            'approved' => $order->pickup_courier_id ?: $order->courier_id ?: $order->delivery_courier_id,
            'courier' => $order->delivery_courier_id ?: $order->courier_id ?: $order->pickup_courier_id,
            default => $order->courier_id ?: $order->pickup_courier_id ?: $order->delivery_courier_id,
        } ?: null;
    }

    private function operationalCourierFor(Order $order): ?User
    {
        $courierId = $this->operationalCourierId($order);

        return $courierId ? User::withTrashed()->find($courierId) : null;
    }

    /** @return array<string, int|string|null> */
    private function complaintSnapshotAttributes(Order $order): array
    {
        $courier = $this->operationalCourierFor($order);
        $courierName = $courier?->name ?: 'مندوب غير مكلّف';

        return [
            'counterparty_id' => $courier?->id,
            'title_ar' => 'شكوى / تأخر — '.$courierName.' — '.$order->track_no,
            'title_en' => 'Complaint / delay — '.$courierName.' — '.$order->track_no,
        ];
    }

    private function completeLegacyComplaintSnapshot(Chat $chat, Order $order): void
    {
        if ($chat->counterparty_id) {
            return;
        }

        $legacyTitle = (string) $chat->title_ar;
        if ($legacyTitle !== '' && ! str_starts_with($legacyTitle, 'شكوى / تأخر —')) {
            return;
        }

        $chat->fill($this->complaintSnapshotAttributes($order));
        if ($chat->isDirty()) {
            $chat->save();
        }
    }

    /** @return array<string, mixed> */
    private function mobileChatPayload(Chat $chat, User $viewer, bool $withListMeta = false): array
    {
        $counterparty = $this->counterpartyForViewer($chat, $viewer);
        $counterpartyName = $counterparty?->name;
        $trackNo = $chat->order?->track_no;

        if ($chat->counterparty_type === 'order_chat') {
            $titleAr = 'محادثة مع '.($counterpartyName ?: 'الطرف الآخر').($trackNo ? ' — '.$trackNo : '');
            $titleEn = 'Chat with '.($counterpartyName ?: 'Counterparty').($trackNo ? ' — '.$trackNo : '');
            $titleKu = 'گفتوگۆ لەگەڵ '.($counterpartyName ?: 'بەرامبەر').($trackNo ? ' — '.$trackNo : '');
        } elseif ($chat->counterparty_type === 'order_support') {
            $counterpartyName ??= 'مندوب غير مكلّف';
            $titleAr = 'شكوى / تأخر — '.$counterpartyName.($trackNo ? ' — '.$trackNo : '');
            $titleEn = 'Complaint / delay — '.$counterpartyName.($trackNo ? ' — '.$trackNo : '');
            $titleKu = 'سکاڵا / دواکەوتن — '.$counterpartyName.($trackNo ? ' — '.$trackNo : '');
        } else {
            $titleAr = $chat->title_ar ?: 'الدعم الفني';
            $titleEn = $chat->title_en ?: 'Support';
            $titleKu = $titleAr;
        }

        $payload = [
            'id' => $chat->id,
            'title_ar' => $titleAr,
            'title_en' => $titleEn,
            'title_ku' => $titleKu,
            'counterparty_name' => $counterpartyName,
            'counterparty_role' => $counterparty?->role,
            'counterparty_type' => $chat->counterparty_type,
            'order_id' => $chat->order_id,
            'track_no' => $trackNo,
            'last_message' => $chat->last_message,
        ];

        if ($withListMeta) {
            $payload['last_at'] = $chat->last_at?->diffForHumans();
            $payload['unread'] = $this->unreadFor($chat, $viewer);
        }

        return $payload;
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
            return $chat->counterparty ?: ($chat->order ? $this->operationalCourierFor($chat->order) : null);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function adminChatPayload(Chat $chat, User $viewer): array
    {
        $order = $chat->order;
        $merchantName = $chat->user?->name;
        $courierName = $chat->counterparty?->name;

        if ($chat->counterparty_type === 'order_support') {
            $courierName ??= $order ? $this->operationalCourierFor($order)?->name : null;
            $displayTitle = 'شكوى / تأخر — '.($courierName ?: 'مندوب غير مكلّف').($order?->track_no ? ' — '.$order->track_no : '');
        } elseif ($chat->counterparty_type === 'order_chat') {
            $displayTitle = 'محادثة الطلب — '.($merchantName ?: 'تاجر').' ↔ '.($courierName ?: 'مندوب').($order?->track_no ? ' — '.$order->track_no : '');
        } else {
            $displayTitle = $chat->title_ar ?: ($merchantName ?: 'الدعم الفني');
        }

        return [
            'id' => $chat->id,
            'title_ar' => $chat->title_ar,
            'title_en' => $chat->title_en,
            'display_title' => $displayTitle,
            'last_message' => $chat->last_message,
            'last_at' => $chat->last_at?->diffForHumans(),
            'unread' => $this->unreadFor($chat, $viewer),
            'user' => $chat->user ? ['name' => $chat->user->name, 'phone' => $chat->user->phone] : null,
            'counterparty' => $chat->counterparty ? ['name' => $chat->counterparty->name, 'phone' => $chat->counterparty->phone] : null,
            'counterparty_type' => $chat->counterparty_type,
            'order' => $this->adminOrderContext($order),
        ];
    }
}
