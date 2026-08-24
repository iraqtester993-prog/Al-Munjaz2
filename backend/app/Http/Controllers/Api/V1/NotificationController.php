<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $notifications = Notification::query()->where(fn ($query) => $query->where('user_id', $user->id)->orWhere(fn ($tenant) => $tenant->whereNull('user_id')->where('tenant_id', $user->tenant_id)))->latest('id')->paginate(min($request->integer('per_page', 30), 100));
        return response()->json(['data' => $notifications->through(fn (Notification $item) => ['id' => $item->id, 'type' => $item->type, 'title' => $item->title_ar, 'body' => $item->body_ar, 'read_at' => $item->read_at?->toISOString(), 'created_at' => $item->created_at?->toISOString(), 'data' => $item->data])]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id || ($notification->user_id === null && $notification->tenant_id === $request->user()->tenant_id), 403);
        $notification->update(['read_at' => now()]);
        return response()->json(['data' => ['id' => $notification->id, 'read_at' => $notification->read_at?->toISOString()]]);
    }
}
