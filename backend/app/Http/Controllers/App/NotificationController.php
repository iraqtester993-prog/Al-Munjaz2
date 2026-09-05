<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use Inertia\Inertia;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $visibleNotifications = $this->visibleNotifications($request->user());
        $notifications = (clone $visibleNotifications)
            ->latest('id')
            ->limit(60)
            ->get();

        // A push can be opened after newer inbox entries arrived. Include the
        // referenced item even when it has moved beyond the normal 60-item
        // inbox window, but only if it is visible to the signed-in user.
        $openId = max(0, $request->integer('open', 0));
        if ($openId > 0 && ! $notifications->contains('id', $openId)) {
            $openedNotification = (clone $visibleNotifications)->find($openId);
            if ($openedNotification) {
                $notifications->push($openedNotification);
                $notifications = $notifications->sortByDesc('id')->values();
            }
        }

        $notifications = $notifications
            ->map(fn (Notification $notification) => $this->present($notification, $request->user()));

        $unread = $notifications->where('read', false)->count();

        return Inertia::render('Mobile/Notifications', [
            'notifications' => $notifications,
            'unread' => $unread,
        ]);
    }

    /**
     * Small JSON feed for the live in-app inbox/banner bridge. It only
     * returns records created after the caller's last id, while the unread
     * count always represents the complete visible inbox.
     */
    public function feed(Request $request)
    {
        $after = max(0, $request->integer('after', 0));
        $query = $this->visibleNotifications($request->user());
        $unread = (clone $query)->whereNull('read_at')->count();
        $latestId = (clone $query)->max('id');

        $notifications = $query
            ->when($after > 0, fn ($builder) => $builder->where('id', '>', $after))
            ->orderBy('id')
            ->limit(60)
            ->get()
            ->map(fn (Notification $notification) => $this->present($notification, $request->user()))
            ->values();

        return response()->json([
            'notifications' => $notifications,
            'unread' => $unread,
            'latest_id' => $latestId,
        ]);
    }

    public function readAll(Request $request)
    {
        // A general campaign produces personal delivery rows, so this safely
        // affects only this account. Legacy tenant-wide rows intentionally
        // stay untouched: they have no per-user read state to update.
        $this->ownedNotifications($request->user())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back();
    }

    /**
     * Mark one delivery in the signed-in user's inbox as read.
     *
     * Dashboard campaigns create one notification row per recipient.  Do not
     * let a user update a tenant-wide/legacy row here: its read state would
     * otherwise be changed for every account that can see that row.
     */
    public function read(Request $request, Notification $notification)
    {
        $notification = $this->ownedNotification($request, $notification);

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'id' => $notification->id,
                    'read_at' => $notification->read_at?->toIso8601String(),
                ],
            ]);
        }

        return back();
    }

    /**
     * Remove only the signed-in user's delivery from their inbox.
     *
     * Notification uses SoftDeletes, so the dashboard campaign and every
     * other recipient's delivery remain intact for audit/history purposes.
     */
    public function destroy(Request $request, Notification $notification)
    {
        $this->ownedNotification($request, $notification)->delete();

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return back();
    }

    private function visibleNotifications($user)
    {
        return Notification::query()
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhere(function ($tenant) use ($user) {
                        $tenant->whereNull('user_id')->where('tenant_id', $user->tenant_id);
                    });
            });
    }

    private function ownedNotifications($user)
    {
        return Notification::query()->where('user_id', $user->id);
    }

    /**
     * A personal inbox operation must never affect another recipient's
     * delivery or a shared legacy notification. Dashboard broadcasts are
     * always per-recipient, which makes individual soft deletion safe.
     */
    private function ownedNotification(Request $request, Notification $notification): Notification
    {
        abort_unless((int) $notification->user_id === (int) $request->user()->id, 403);

        return $notification;
    }

    private function present(Notification $notification, $user): array
    {
        $isOwner = (int) $notification->user_id === (int) $user->id;

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->titleFor(),
            'body' => $notification->bodyFor(),
            'read' => $notification->read_at !== null,
            // Legacy tenant-wide rows are still readable, but have no single
            // owner and therefore cannot be changed from one user's inbox.
            'can_manage' => $isOwner,
            'time' => $notification->created_at->diffForHumans(),
            'created_at' => $notification->created_at?->toIso8601String(),
            // Keep navigation server-defined. The browser never decides a
            // target from the notification title/body, and each destination
            // still enforces its own order/chat access policy.
            'target_url' => $this->targetUrl($notification),
        ];
    }

    private function targetUrl(Notification $notification): ?string
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $explicitUrl = $data['url'] ?? null;

        // Existing chat notifications already carry a specific internal URL.
        // Accept only an application path to prevent a stored notification
        // from becoming an arbitrary redirect.
        if (is_string($explicitUrl) && str_starts_with($explicitUrl, '/app/') && ! str_contains($explicitUrl, '//')) {
            return $explicitUrl;
        }

        $chatId = filter_var($data['chat_id'] ?? null, FILTER_VALIDATE_INT);
        if ($chatId) {
            return '/app/chats/'.$chatId;
        }

        // Finance and account messages may carry an order id as audit context,
        // but opening them must show their message sheet rather than jumping
        // the user away from the inbox. Only an actual order notification
        // owns the order-details destination.
        $orderId = filter_var($data['order_id'] ?? null, FILTER_VALIDATE_INT);
        if ($notification->type === 'order' && $orderId) {
            return '/app/orders?open='.$orderId.'&list=1';
        }

        return null;
    }
}
