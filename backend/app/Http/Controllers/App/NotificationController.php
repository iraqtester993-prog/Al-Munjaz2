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
        $notifications = $this->visibleNotifications($request->user())
            ->latest('id')
            ->limit(60)
            ->get()
            ->map(fn (Notification $notification) => $this->present($notification));

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
            ->map(fn (Notification $notification) => $this->present($notification))
            ->values();

        return response()->json([
            'notifications' => $notifications,
            'unread' => $unread,
            'latest_id' => $latestId,
        ]);
    }

    public function readAll(Request $request)
    {
        $this->visibleNotifications($request->user())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

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

    private function present(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->titleFor(),
            'body' => $notification->bodyFor(),
            'read' => $notification->read_at !== null,
            'time' => $notification->created_at->diffForHumans(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
