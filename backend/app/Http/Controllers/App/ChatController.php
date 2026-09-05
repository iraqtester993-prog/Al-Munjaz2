<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\BranchDashboardContext;
use App\Services\BranchDashboardScope;
use App\Services\CourierOrderAccess;
use App\Services\DashboardBranchFilter;
use App\Services\PusherChatPublisher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class ChatController extends Controller
{
    /**
     * Conversations can grow indefinitely, but neither the mobile PWA nor
     * the dashboard needs to transfer an entire history to render a thread.
     * Keep the first paint responsive and let the existing incremental poll
     * catch up in predictable, indexed pages when a client reconnects after
     * a long time away.
     */
    private const CHAT_LIST_LIMIT = 150;

    private const INITIAL_MESSAGE_LIMIT = 100;

    private const INCREMENTAL_MESSAGE_LIMIT = 100;

    public function index(Request $request)
    {
        $user = $request->user();

        $chats = $this->withViewerUnreadCount($this->chatsFor($user), $user)
            ->with([
                'user:id,name,role,shop_name',
                'counterparty:id,name,role,shop_name',
                'order:id,track_no,courier_id,merchant_id,pickup_courier_id,delivery_courier_id',
            ])
            ->orderByDesc('last_at')
            ->limit(self::CHAT_LIST_LIMIT)
            ->get()
            ->map(fn (Chat $chat) => $this->mobileChatPayload($chat, $user, true));

        return Inertia::render('Mobile/Chats', ['chats' => $chats]);
    }

    public function show(Request $request, Chat $chat)
    {
        $this->ensureParticipant($request, $chat);
        $messages = $this->messagesFor($chat, $request->user());
        $this->markRead($chat, $request->user());

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
        $recipients = $this->notifyMessageRecipients($chat, $request->user());
        app(PusherChatPublisher::class)->publish($message, $recipients);

        return response()->json($this->messagePayload(
            $message->load('sender:id,name,role'),
            $request->user(),
        ));
    }

    /**
     * Lightweight polling endpoint.  It deliberately returns JSON only, so
     * an open conversation updates without re-rendering the entire Inertia
     * page or moving the user out of the current scroll position.
     */
    public function messages(Request $request, Chat $chat)
    {
        $this->ensureParticipant($request, $chat);

        $data = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
        ]);
        $messages = $this->messagesFor($chat, $request->user(), $data['after_id'] ?? null);
        $this->markReadFromMessages($chat, $request->user(), $messages);

        return response()->json([
            'messages' => $messages,
            // Use the last message actually returned, not a new row that
            // might have arrived between the incremental query and this JSON
            // response. Advancing past an unseen ID would silently skip it.
            'last_id' => (int) (data_get($messages->last(), 'id') ?? ($data['after_id'] ?? 0)),
            // This endpoint marks every incoming message in the returned
            // page as read, so no extra COUNT query is needed on each poll.
            'unread' => 0,
        ], 200, ['Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0']);
    }

    /** Keeps a short server-side presence lease while this exact thread is visible. */
    public function presence(Request $request, Chat $chat)
    {
        $this->ensureParticipant($request, $chat);
        Cache::put($this->presenceKey($chat, (int) $request->user()->id), true, now()->addSeconds(75));

        return response()->noContent();
    }

    /** Used by the permanent bottom-chat badge without navigating to chat. */
    public function unread(Request $request)
    {
        $count = $this->withViewerUnreadCount($this->chatsFor($request->user()), $request->user())
            ->get()
            ->sum('viewer_unread');

        return response()->json(['unread' => (int) $count]);
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

            // An order support request always reaches operations. Keep the
            // audited order context, but label it by courier and order rather
            // than presenting it to the merchant as a "complaint".
            if ($request->boolean('complaint')) {
                abort_unless($user->role === 'merchant' || $user->isCourierRole(), 403);

                $supportSnapshot = $user->isCourierRole()
                    ? $this->courierCancellationSnapshotAttributes($order, $user)
                    : $this->complaintSnapshotAttributes($order);

                $chat = Chat::withoutGlobalScope(TenantScope::class)->firstOrCreate(
                    [
                        'tenant_id' => $order->tenant_id,
                        'user_id' => $user->id,
                        'counterparty_type' => 'order_support',
                        'order_id' => $order->id,
                    ],
                    $supportSnapshot + [
                        'last_message' => '',
                        'last_at' => now(),
                    ]
                );

                // Legacy complaints did not retain which courier the issue
                // referred to. Attach a snapshot the first time one is
                // opened so the dashboard has a stable, auditable label.
                if ($user->role === 'merchant') {
                    $this->completeLegacyComplaintSnapshot($chat, $order);
                }

                return redirect()->route('app.chats.show', $chat);
            }

            // A direct order has one courier from pickup through delivery. A
            // courier opens their own conversation; a merchant opens the one
            // operational courier conversation for the order.
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
        $scope = $this->branchScope($request);
        $branchFilter = app(DashboardBranchFilter::class);
        $selectedBranchId = $branchFilter->selectedBranchId($request, $scope);

        return Inertia::render('Admin/Chat', $this->adminInboxProps($request->user(), $scope, $selectedBranchId) + [
            'branchFilter' => $branchFilter->payload($request, $scope),
        ]);
    }

    public function adminShow(Request $request, Chat $chat)
    {
        $scope = $this->branchScope($request);
        $branchFilter = app(DashboardBranchFilter::class);
        $selectedBranchId = $branchFilter->selectedBranchId($request, $scope);
        $this->ensureAdminChat($chat, $scope, $selectedBranchId);
        $messages = $this->messagesFor($chat, $request->user(), null, $scope);
        $this->markRead($chat, $request->user(), $scope);

        $chat->loadMissing([
            'user:id,branch_id,name,phone,role,shop_name',
            'counterparty:id,branch_id,name,phone,role,shop_name',
            'order:id,branch_id,origin_branch_id,destination_branch_id,track_no,customer_name_ar,customer_name_en,phone,address_ar,address_en,status',
        ]);

        return Inertia::render('Admin/Chat', $this->adminInboxProps($request->user(), $scope, $selectedBranchId) + [
            'activeChat' => $this->adminChatPayload($chat, $request->user(), $scope),
            'messages' => $messages,
            'branchFilter' => $branchFilter->payload($request, $scope),
        ]);
    }

    public function adminSend(Request $request, Chat $chat)
    {
        // The merchant/courier tab is a transparent audit view. The
        // dashboard must never become an unnoticed third participant in a
        // private order conversation.
        $scope = $this->branchScope($request);
        $selectedBranchId = app(DashboardBranchFilter::class)->selectedBranchId($request, $scope);
        $this->ensureAdminSupportChat($chat, $scope, $selectedBranchId);
        $this->ensureAdminReplyRecipient($chat, $scope);
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
        $recipients = $this->notifyMessageRecipients($chat, $request->user(), true);
        app(PusherChatPublisher::class)->publish($message, $recipients);

        return response()->json($this->messagePayload(
            $message->load('sender:id,branch_id,name,role'),
            $request->user(),
            $scope,
        ));
    }

    public function adminMessages(Request $request, Chat $chat)
    {
        $scope = $this->branchScope($request);
        $selectedBranchId = app(DashboardBranchFilter::class)->selectedBranchId($request, $scope);
        $this->ensureAdminChat($chat, $scope, $selectedBranchId);

        $data = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
        ]);
        $messages = $this->messagesFor($chat, $request->user(), $data['after_id'] ?? null, $scope);
        $this->markReadFromMessages($chat, $request->user(), $messages, $scope);

        return response()->json([
            'messages' => $messages,
            'last_id' => (int) (data_get($messages->last(), 'id') ?? ($data['after_id'] ?? 0)),
            'unread' => 0,
        ], 200, ['Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0']);
    }

    /**
     * The dashboard has two deliberately separated inboxes: technical
     * support (reply allowed) and merchant/courier order chats (review only).
     * Unknown historical chat markers remain excluded by the model scope.
     */
    private function adminChats(?BranchDashboardScope $scope = null, ?int $selectedBranchId = null): Builder
    {
        return $this->scopeAdminChats(
            Chat::withoutGlobalScope(TenantScope::class)->adminDashboardInbox(),
            $scope,
            $selectedBranchId,
        );
    }

    private function adminSupportChats(?BranchDashboardScope $scope = null, ?int $selectedBranchId = null): Builder
    {
        return $this->scopeAdminChats(
            Chat::withoutGlobalScope(TenantScope::class)->adminSupportInbox(),
            $scope,
            $selectedBranchId,
        );
    }

    private function adminMerchantCourierChats(?BranchDashboardScope $scope = null, ?int $selectedBranchId = null): Builder
    {
        return $this->scopeAdminChats(
            Chat::withoutGlobalScope(TenantScope::class)->adminMerchantCourierInbox(),
            $scope,
            $selectedBranchId,
        );
    }

    private function ensureAdminChat(Chat $chat, ?BranchDashboardScope $scope = null, ?int $selectedBranchId = null): void
    {
        abort_unless($this->adminChats($scope, $selectedBranchId)->whereKey($chat->id)->exists(), 404);
    }

    private function ensureAdminSupportChat(Chat $chat, ?BranchDashboardScope $scope = null, ?int $selectedBranchId = null): void
    {
        abort_unless($this->adminSupportChats($scope, $selectedBranchId)->whereKey($chat->id)->exists(), 404);
    }

    /**
     * Keep the legacy `chats` prop limited to support so the current Vue
     * screen stays backward compatible while the dashboard receives the two
     * explicit data sets it needs for tabs.
     *
     * @return array<string, mixed>
     */
    private function adminInboxProps(User $viewer, ?BranchDashboardScope $scope = null, ?int $selectedBranchId = null): array
    {
        $supportChats = $this->withViewerUnreadCount($this->adminSupportChats($scope, $selectedBranchId), $viewer, $scope)
            ->with($this->adminChatRelations())
            ->orderByDesc('last_at')
            ->limit(self::CHAT_LIST_LIMIT)
            ->get()
            ->map(fn (Chat $chat) => $this->adminChatPayload($chat, $viewer, $scope));

        // Fetch each inbox independently. A burst of support messages must
        // not hide operational order conversations (or the reverse), while
        // the fixed recent window prevents the dashboard boot from loading
        // years of chat rows and their message-count queries.
        $merchantCourierChats = $this->withViewerUnreadCount($this->adminMerchantCourierChats($scope, $selectedBranchId), $viewer, $scope)
            ->with($this->adminChatRelations())
            ->orderByDesc('last_at')
            ->limit(self::CHAT_LIST_LIMIT)
            ->get()
            ->map(fn (Chat $chat) => $this->adminChatPayload($chat, $viewer, $scope));

        return [
            // Kept for the pre-tab dashboard client.
            'chats' => $supportChats,
            'supportChats' => $supportChats,
            'merchantCourierChats' => $merchantCourierChats,
            'chatTabs' => [
                ['key' => 'support', 'label' => 'الدعم الفني', 'count' => $supportChats->count(), 'read_only' => false],
                ['key' => 'merchant_courier', 'label' => 'دردشات التجار والمندوبين', 'count' => $merchantCourierChats->count(), 'read_only' => true],
            ],
        ];
    }

    /** @return array<int, string> */
    private function adminChatRelations(): array
    {
        return [
            'user:id,branch_id,name,phone,role,shop_name',
            'counterparty:id,branch_id,name,phone,role,shop_name',
            'order:id,branch_id,origin_branch_id,destination_branch_id,track_no,customer_name_ar,customer_name_en,phone,address_ar,address_en,status',
        ];
    }

    /**
     * A chat row is not branch-owned itself, so its boundary comes from the
     * people and operational entity it represents. An order thread is local
     * only when both participants belong to this branch and the order
     * touches it. A general support thread belongs to its local requester.
     * This intentionally hides cross-branch conversations rather than merely
     * hiding names: a message body can itself contain private branch data.
     */
    private function scopeAdminChats(Builder $chats, ?BranchDashboardScope $scope, ?int $selectedBranchId = null): Builder
    {
        $branchId = $scope?->hasBranchScope() ? $scope->branchId() : $selectedBranchId;

        if (! $branchId) {
            return $chats;
        }

        $chatTable = (new Chat)->getTable();
        $orderTable = (new Order)->getTable();
        $userTable = (new User)->getTable();

        $orders = Order::withoutGlobalScope(TenantScope::class)
            ->withTrashed()
            ->selectRaw('1')
            ->whereColumn("{$orderTable}.id", "{$chatTable}.order_id");
        app(DashboardBranchFilter::class)->restrictOrders($orders, $branchId);

        $localRequester = User::query()
            ->selectRaw('1')
            ->whereColumn("{$userTable}.id", "{$chatTable}.user_id")
            ->where("{$userTable}.branch_id", $branchId);

        $localCounterparty = User::query()
            ->selectRaw('1')
            ->whereColumn("{$userTable}.id", "{$chatTable}.counterparty_id")
            ->where("{$userTable}.branch_id", $branchId);

        // A selected branch is an audit filter for the super administrator.
        // Unlike the local dashboard boundary, it must retain cross-branch
        // order conversations whenever the order or either participant
        // belongs to the selected branch.
        if (! $scope?->hasBranchScope()) {
            return $chats->where(function (Builder $visible) use ($orders, $localRequester, $localCounterparty): void {
                $visible
                    ->whereExists($orders->toBase())
                    ->orWhereExists($localRequester->toBase())
                    ->orWhereExists($localCounterparty->toBase());
            });
        }

        return $chats->where(function (Builder $visible) use ($orders, $localRequester, $localCounterparty, $chatTable): void {
            $visible
                ->where(function (Builder $orderChats) use ($orders, $localRequester, $localCounterparty, $chatTable): void {
                    $orderChats
                        ->where("{$chatTable}.counterparty_type", 'order_chat')
                        ->whereExists($orders->toBase())
                        ->whereExists($localRequester->toBase())
                        ->whereExists($localCounterparty->toBase());
                })
                ->orWhere(function (Builder $support) use ($orders, $localRequester, $chatTable): void {
                    $support
                        ->whereIn("{$chatTable}.counterparty_type", ['support', 'order_support'])
                        ->whereExists($localRequester->toBase())
                        ->where(function (Builder $supportOrder) use ($orders, $chatTable): void {
                            $supportOrder
                                ->whereNull("{$chatTable}.order_id")
                                ->orWhereExists($orders->toBase());
                        });
                });
        });
    }

    private function branchScope(Request $request): BranchDashboardScope
    {
        $scope = app(BranchDashboardContext::class)->fromRequest($request);

        if ($scope->requiresBranchScope()) {
            abort_unless($scope->hasBranchScope(), 403);
        }

        // Keep the non-branch platform scope rather than returning null.
        // DashboardBranchFilter needs this object to distinguish a regular
        // employee (no selector) from a super administrator (selector
        // enabled), while the existing optional-scope helpers continue to
        // treat it as unrestricted data scope.
        return $scope;
    }

    private function ensureAdminReplyRecipient(Chat $chat, ?BranchDashboardScope $scope): void
    {
        if (! $scope?->hasBranchScope()) {
            return;
        }

        $recipient = User::query()->select(['id', 'branch_id'])->find($chat->user_id);

        // A branch dashboard can inspect an inter-branch order thread for
        // its own operational endpoint, but it must not send a support reply
        // to an account owned by another branch.
        abort_unless($this->visibleAdminParticipant($recipient, $scope), 404);
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

    private function markRead(Chat $chat, User $user, ?BranchDashboardScope $scope = null): void
    {
        $isDashboardOperator = $this->isDashboardChatOperator($user, $scope);
        $key = $this->readColumnFor($chat, $user, $scope);
        $readAt = $chat->{$key};

        // A thread that is already caught up should be entirely read-only.
        // Polling used to UPDATE this row every few seconds even when no one
        // had written anything, which becomes a costly write hotspot on a
        // shared host.
        $hasUnread = $chat->messages()
            ->where('sender_id', '!=', $user->id)
            ->when($readAt, fn (Builder $messages) => $messages->where('created_at', '>', $readAt))
            ->exists();

        if (! $hasUnread) {
            return;
        }

        $attributes = [$key => now()];
        if ($isDashboardOperator) {
            $attributes['unread'] = 0;
        }

        $chat->forceFill($attributes)->save();
    }

    private function unreadFor(Chat $chat, User $user, ?BranchDashboardScope $scope = null): int
    {
        // List queries attach this aggregate once for every row, avoiding an
        // N+1 message COUNT while the inbox is being rendered.
        if (array_key_exists('viewer_unread', $chat->getAttributes())) {
            return (int) $chat->getAttribute('viewer_unread');
        }

        $readAt = $chat->{$this->readColumnFor($chat, $user, $scope)};

        return $chat->messages()
            ->where('sender_id', '!=', $user->id)
            ->when($readAt, fn ($query) => $query->where('created_at', '>', $readAt))
            ->count();
    }

    private function markReadFromMessages(Chat $chat, User $user, $messages, ?BranchDashboardScope $scope = null): void
    {
        $isDashboardOperator = $this->isDashboardChatOperator($user, $scope);
        $key = $this->readColumnFor($chat, $user, $scope);
        $readAt = $chat->{$key};

        $latestIncomingAt = collect($messages)
            ->filter(fn (array $message) => (int) ($message['sender_id'] ?? 0) !== (int) $user->id)
            ->map(fn (array $message) => $message['created_at'] ?? null)
            ->filter()
            ->map(fn (string $createdAt) => Carbon::parse($createdAt)->getTimestamp())
            ->max();

        if (! $latestIncomingAt || ($readAt && $latestIncomingAt <= $readAt->getTimestamp())) {
            return;
        }

        $attributes = [$key => now()];
        if ($isDashboardOperator) {
            $attributes['unread'] = 0;
        }

        $chat->forceFill($attributes)->save();
    }

    private function messagesFor(Chat $chat, User $user, ?int $afterId = null, ?BranchDashboardScope $scope = null)
    {
        $messages = $chat->messages()
            ->with('sender:id,branch_id,name,role')
            ->when($afterId !== null && $afterId > 0, fn (Builder $query) => $query->where('id', '>', $afterId));

        if ($afterId !== null && $afterId > 0) {
            return $messages
                ->orderBy('id')
                ->limit(self::INCREMENTAL_MESSAGE_LIMIT)
                ->get()
                ->map(fn (ChatMessage $message) => $this->messagePayload($message, $user, $scope))
                ->values();
        }

        // Fetch the newest slice efficiently (DESC + LIMIT), then restore
        // chronological display order for the existing Vue conversation UI.
        return $messages
            ->orderByDesc('id')
            ->limit(self::INITIAL_MESSAGE_LIMIT)
            ->get()
            ->sortBy('id')
            ->map(fn (ChatMessage $message) => $this->messagePayload($message, $user, $scope))
            ->values();
    }

    /**
     * Add the current viewer's unread total as one correlated aggregate per
     * chat row. It is intentionally calculated using the same cursor rule
     * as readColumnFor(), including the second side of direct order chats.
     */
    private function withViewerUnreadCount(Builder $chats, User $viewer, ?BranchDashboardScope $scope = null): Builder
    {
        $chatTable = (new Chat)->getTable();
        $messageTable = (new ChatMessage)->getTable();
        $isDashboardOperator = $this->isDashboardChatOperator($viewer, $scope);

        return $chats->withCount([
            'messages as viewer_unread' => function (Builder $messages) use ($viewer, $chatTable, $messageTable, $isDashboardOperator): void {
                $messages->where("{$messageTable}.sender_id", '!=', $viewer->id)
                    ->where(function (Builder $unread) use ($viewer, $chatTable, $messageTable, $isDashboardOperator): void {
                        if ($isDashboardOperator) {
                            $this->applyUnreadReadCursor($unread, "{$chatTable}.admin_read_at", "{$messageTable}.created_at");

                            return;
                        }

                        $unread
                            ->where(function (Builder $counterparty) use ($viewer, $chatTable, $messageTable): void {
                                $counterparty
                                    ->where("{$chatTable}.counterparty_type", 'order_chat')
                                    ->where("{$chatTable}.counterparty_id", $viewer->id);

                                $this->applyUnreadReadCursor($counterparty, "{$chatTable}.counterparty_read_at", "{$messageTable}.created_at");
                            })
                            ->orWhere(function (Builder $primary) use ($viewer, $chatTable, $messageTable): void {
                                $primary
                                    ->where(function (Builder $notCounterparty) use ($viewer, $chatTable): void {
                                        $notCounterparty
                                            ->where("{$chatTable}.counterparty_type", '!=', 'order_chat')
                                            ->orWhereNull("{$chatTable}.counterparty_type")
                                            ->orWhere("{$chatTable}.counterparty_id", '!=', $viewer->id)
                                            ->orWhereNull("{$chatTable}.counterparty_id");
                                    });

                                $this->applyUnreadReadCursor($primary, "{$chatTable}.user_read_at", "{$messageTable}.created_at");
                            });
                    });
            },
        ]);
    }

    private function applyUnreadReadCursor(Builder $query, string $readColumn, string $messageCreatedAt): void
    {
        $query->where(function (Builder $cursor) use ($readColumn, $messageCreatedAt): void {
            $cursor
                ->whereNull($readColumn)
                ->orWhereColumn($messageCreatedAt, '>', $readColumn);
        });
    }

    /** @return array<string, mixed> */
    private function messagePayload(ChatMessage $message, User $viewer, ?BranchDashboardScope $scope = null): array
    {
        $senderIsVisible = ! $scope?->hasBranchScope()
            || $this->visibleAdminParticipant($message->sender, $scope);

        return [
            'id' => $message->id,
            'sender_id' => $senderIsVisible ? $message->sender_id : null,
            'sender_name' => $senderIsVisible
                ? ($message->sender?->name ?: 'مستخدم محذوف')
                : 'مستخدم من فرع آخر',
            'sender_role' => $senderIsVisible ? ($message->sender?->role ?: 'unknown') : 'unknown',
            'from_me' => $message->sender_id === $viewer->id,
            'text' => $message->text,
            'time' => $message->created_at->format('H:i'),
            'created_at' => $message->created_at?->toISOString(),
        ];
    }

    /**
     * A direct order conversation has two real participants. A reply by one
     * participant reaches the other, while an administrator reply reaches
     * both mobile participants. Support conversations remain private to the
     * originating user and are never broadcast to every operator.
     */
    private function notifyMessageRecipients(Chat $chat, User $sender, bool $senderIsDashboardOperator = false): array
    {
        $recipientIds = [];

        if ($chat->counterparty_type === 'order_chat') {
            $recipientIds = [$chat->user_id, $chat->counterparty_id];
        } elseif (($sender->isAdmin() || $senderIsDashboardOperator) && $chat->user_id && $chat->user_id !== $sender->id) {
            $recipientIds = [$chat->user_id];
        }

        $recipientIds = collect($recipientIds)
            ->filter(fn ($id) => $id && (int) $id !== (int) $sender->id)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($recipientIds->isEmpty()) {
            return [];
        }

        User::query()
            ->whereIn('id', $recipientIds)
            ->where('status', 'active')
            ->get()
            ->each(function (User $recipient) use ($chat): void {
                // An open thread already receives its Pusher event and is
                // marked read on arrival; never create a noisy device push.
                if (Cache::has($this->presenceKey($chat, (int) $recipient->id))) {
                    return;
                }

                // A message burst in one background conversation creates one
                // actionable notification, not a phone alert per message.
                $alreadyNotified = Notification::query()
                    ->where('user_id', $recipient->id)
                    ->where('type', 'chat')
                    ->where('created_at', '>=', now()->subMinutes(2))
                    ->whereJsonContains('data->chat_id', $chat->id)
                    ->exists();
                if ($alreadyNotified) return;

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

        return $recipientIds->all();
    }

    private function presenceKey(Chat $chat, int $userId): string
    {
        return 'chat:presence:'.$chat->id.':'.$userId;
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

        abort_unless($user->role === 'courier', 403);

        $access = app(CourierOrderAccess::class);
        $canAccess = $access->assigned($user)->whereKey($order->id)->exists()
            || $access->available($user)->whereKey($order->id)->exists();

        abort_unless($canAccess, 403);
    }

    /** @return array<int, int> */
    private function assignedCourierIds(Order $order): array
    {
        return collect([$order->courier_id])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function directCourierIdFor(Order $order, User $actor): ?int
    {
        $courierIds = $this->assignedCourierIds($order);

        if ($actor->role === 'courier' && in_array((int) $actor->id, $courierIds, true)) {
            return (int) $actor->id;
        }

        // For an available offer the authenticated courier is the intended
        // counterparty for a pre-acceptance clarification conversation.
        if ($actor->role === 'courier' && $courierIds === []) {
            return (int) $actor->id;
        }

        // The merchant is connected to the same accountable courier shown on
        // the order card.
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

    private function readColumnFor(Chat $chat, User $user, ?BranchDashboardScope $scope = null): string
    {
        if ($this->isDashboardChatOperator($user, $scope)) {
            return 'admin_read_at';
        }

        return $chat->counterparty_type === 'order_chat' && $chat->counterparty_id === $user->id
            ? 'counterparty_read_at'
            : 'user_read_at';
    }

    private function adminOrderContext(?Order $order, ?BranchDashboardScope $scope = null): ?array
    {
        return $this->visibleAdminOrder($order, $scope) ? [
            'id' => $order->id,
            'track_no' => $order->track_no,
            'customer_name' => $order->customer_name_ar ?: $order->customer_name_en,
            'phone' => $order->phone,
            'address' => $order->address_ar ?: $order->address_en,
            'status' => $order->status,
        ] : null;
    }

    /** Resolve the one courier shown for an order, with legacy read fallback. */
    private function operationalCourierId(Order $order): ?int
    {
        return $order->courier_id ?: $order->delivery_courier_id ?: $order->pickup_courier_id;
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
            'title_ar' => 'دعم الطلب — '.$courierName.' — '.$order->track_no,
            'title_en' => 'Order support — '.$courierName.' — '.$order->track_no,
        ];
    }

    /** @return array<string, int|string|null> */
    private function courierCancellationSnapshotAttributes(Order $order, User $courier): array
    {
        $merchantId = $this->merchantIdForOrder($order, $courier);
        $merchant = $merchantId ? User::withTrashed()->find($merchantId) : null;
        $merchantName = $merchant?->shop_name ?: ($merchant?->name ?: 'تاجر غير محدد');

        return [
            'counterparty_id' => $merchant?->id,
            'title_ar' => 'دعم إلغاء الطلب — '.$merchantName.' — '.$order->track_no,
            'title_en' => 'Cancellation support — '.$merchantName.' — '.$order->track_no,
        ];
    }

    private function completeLegacyComplaintSnapshot(Chat $chat, Order $order): void
    {
        if ($chat->counterparty_id) {
            return;
        }

        $legacyTitle = (string) $chat->title_ar;
        if ($legacyTitle !== '' && ! str_starts_with($legacyTitle, 'شكوى / تأخر —') && ! str_starts_with($legacyTitle, 'دعم الطلب —')) {
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
            $titleAr = 'دعم الطلب — '.$counterpartyName.($trackNo ? ' — '.$trackNo : '');
            $titleEn = 'Order support — '.$counterpartyName.($trackNo ? ' — '.$trackNo : '');
            $titleKu = 'پشتیوانی داواکاری — '.$counterpartyName.($trackNo ? ' — '.$trackNo : '');
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
    private function adminChatPayload(Chat $chat, User $viewer, ?BranchDashboardScope $scope = null): array
    {
        $order = $this->visibleAdminOrder($chat->order, $scope) ? $chat->order : null;
        $isMerchantCourierChat = $chat->counterparty_type === 'order_chat';
        $merchant = ($chat->user?->role === 'merchant' || $chat->user?->role === 'owner')
            ? $chat->user
            : null;
        $courier = $chat->counterparty?->isCourierRole() ? $chat->counterparty : null;

        // Direct order chats always store the merchant as `user` and the
        // intended courier as `counterparty`; keep that role contract
        // explicit for the dashboard instead of depending on display text.
        if ($isMerchantCourierChat) {
            $merchant = $chat->user;
            $courier = $chat->counterparty;
        }

        $merchantName = $this->visibleAdminParticipant($merchant, $scope) ? $merchant?->name : null;
        $courierName = $this->visibleAdminParticipant($courier, $scope) ? $courier?->name : null;
        $isScopedBranchDashboard = $scope?->hasBranchScope() ?? false;

        if ($chat->counterparty_type === 'order_support') {
            $displayTitle = ($isScopedBranchDashboard ? 'دعم الطلب' : ($chat->title_ar ?: 'دعم الطلب'))
                .($order?->track_no ? ' — '.$order->track_no : '');
        } elseif ($isMerchantCourierChat) {
            $displayTitle = 'محادثة الطلب — '.($merchantName ?: 'تاجر').' ↔ '.($courierName ?: 'مندوب').($order?->track_no ? ' — '.$order->track_no : '');
        } else {
            $displayTitle = $isScopedBranchDashboard
                ? ($merchantName ?: 'الدعم الفني')
                : ($chat->title_ar ?: ($merchantName ?: 'الدعم الفني'));
        }

        return [
            'id' => $chat->id,
            // Historical order-support titles include the other participant's
            // name. Never serialise those raw values into a branch dashboard.
            'title_ar' => $isScopedBranchDashboard ? $displayTitle : $chat->title_ar,
            'title_en' => $isScopedBranchDashboard ? null : $chat->title_en,
            'display_title' => $displayTitle,
            'last_message' => $chat->last_message,
            'last_at' => $chat->last_at?->diffForHumans(),
            'unread' => $this->unreadFor($chat, $viewer, $scope),
            'channel' => $isMerchantCourierChat ? 'merchant_courier' : 'support',
            'read_only' => $isMerchantCourierChat,
            'can_reply' => ! $isMerchantCourierChat
                && $viewer->canUseAdminPermission('chat', 'reply')
                && $this->visibleAdminParticipant($chat->user, $scope),
            'user' => $this->adminParticipantPayload($chat->user, $scope),
            'counterparty' => $this->adminParticipantPayload($chat->counterparty, $scope),
            'merchant' => $this->adminParticipantPayload($merchant, $scope),
            'courier' => $this->adminParticipantPayload($courier, $scope),
            'merchant_name' => $merchantName,
            'courier_name' => $courierName,
            'counterparty_type' => $chat->counterparty_type,
            'order_id' => $order?->id,
            'track_no' => $order?->track_no,
            'tracking_no' => $order?->track_no,
            'order' => $this->adminOrderContext($order, $scope),
        ];
    }

    /** @return array<string, int|string|null>|null */
    private function adminParticipantPayload(?User $user, ?BranchDashboardScope $scope = null): ?array
    {
        return $this->visibleAdminParticipant($user, $scope) ? [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'role' => $user->role,
            'shop_name' => $user->shop_name,
        ] : null;
    }

    private function visibleAdminParticipant(?User $user, ?BranchDashboardScope $scope = null): bool
    {
        return $user !== null
            && (! $scope?->hasBranchScope() || (int) $user->branch_id === (int) $scope->branchId());
    }

    private function visibleAdminOrder(?Order $order, ?BranchDashboardScope $scope = null): bool
    {
        if (! $order) {
            return false;
        }

        if (! $scope?->hasBranchScope()) {
            return true;
        }

        return in_array((int) $scope->branchId(), [
            (int) $order->branch_id,
            (int) $order->origin_branch_id,
            (int) $order->destination_branch_id,
        ], true);
    }

    private function isDashboardChatOperator(User $user, ?BranchDashboardScope $scope = null): bool
    {
        return $user->isAdmin() || $scope?->hasBranchScope() === true;
    }
}
