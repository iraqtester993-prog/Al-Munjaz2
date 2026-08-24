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
        $notifications = Notification::query()
            ->where(function ($q) use ($request) {
                $q->where('user_id', $request->user()->id)->orWhereNull('user_id');
            })
            ->latest('id')
            ->limit(60)
            ->get()
            ->map(fn (Notification $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->titleFor(),
                'body' => $n->bodyFor(),
                'read' => $n->read_at !== null,
                'time' => $n->created_at->diffForHumans(),
            ]);

        $unread = $notifications->where('read', false)->count();

        return Inertia::render('Mobile/Notifications', [
            'notifications' => $notifications,
            'unread' => $unread,
        ]);
    }

    public function readAll(Request $request)
    {
        Notification::query()
            ->where(function ($q) use ($request) {
                $q->where('user_id', $request->user()->id)->orWhereNull('user_id');
            })
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back();
    }
}
