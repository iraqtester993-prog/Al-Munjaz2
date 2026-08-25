<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $chats = Chat::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_at')
            ->get()
            ->map(fn (Chat $chat) => [
                'id' => $chat->id,
                'title_ar' => $chat->title_ar,
                'title_en' => $chat->title_en,
                'last_message' => $chat->last_message,
                'last_at' => $chat->last_at?->diffForHumans(),
                'unread' => $chat->unread,
            ]);

        return Inertia::render('Mobile/Chats', ['chats' => $chats]);
    }

    public function show(Request $request, Chat $chat)
    {
        abort_unless($chat->user_id === $request->user()->id || $request->user()->isAdmin(), 403);

        $chat->update(['unread' => 0]);

        $messages = $chat->messages()->orderBy('id')->get()->map(fn (ChatMessage $m) => [
            'id' => $m->id,
            'sender_id' => $m->sender_id,
            'from_me' => $request->user()->isAdmin() ? $m->sender_id === $request->user()->id : $m->sender_id === $request->user()->id,
            'text' => $m->text,
            'time' => $m->created_at->format('H:i'),
        ]);

        return Inertia::render('Mobile/ChatThread', [
            'chat' => [
                'id' => $chat->id,
                'title_ar' => $chat->title_ar,
                'title_en' => $chat->title_en,
                'counterparty_type' => $chat->counterparty_type,
            ],
            'messages' => $messages,
        ]);
    }

    public function send(Request $request, Chat $chat)
    {
        $request->validate(['text' => ['required', 'string', 'max:1000']]);

        abort_unless($chat->user_id === $request->user()->id || $request->user()->isAdmin(), 403);

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

        return response()->json([
            'id' => $message->id,
            'text' => $message->text,
            'from_me' => true,
            'time' => $message->created_at->format('H:i'),
        ]);
    }

    public function open(Request $request)
    {
        $user = $request->user();
        $tenant = TenantContext::tenant();

        $data = $request->validate([
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
        ]);

        if (! empty($data['order_id'])) {
            $order = Order::withoutGlobalScope(TenantScope::class)->findOrFail($data['order_id']);

            if ($user->role === 'merchant') {
                abort_unless($order->tenant_id === $user->tenant_id, 403);
            } elseif ($user->role === 'courier') {
                abort_unless($order->courier_id === $user->id, 403);
            } else {
                abort(403);
            }

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
            ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'counterparty_type' => 'support'],
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
        $chats = Chat::query()
            ->with('user:id,name,phone')
            ->orderByDesc('last_at')
            ->get()
            ->map(fn (Chat $chat) => [
                'id' => $chat->id,
                'title_ar' => $chat->title_ar,
                'title_en' => $chat->title_en,
                'last_message' => $chat->last_message,
                'last_at' => $chat->last_at?->diffForHumans(),
                'unread' => $chat->unread,
                'user' => $chat->user ? ['name' => $chat->user->name, 'phone' => $chat->user->phone] : null,
                'counterparty_type' => $chat->counterparty_type,
            ]);

        return Inertia::render('Admin/Chat', ['chats' => $chats]);
    }

    public function adminShow(Request $request, Chat $chat)
    {
        $chat->update(['unread' => 0]);

        $messages = $chat->messages()->orderBy('id')->get()->map(fn (ChatMessage $m) => [
            'id' => $m->id,
            'sender_id' => $m->sender_id,
            'from_me' => $m->sender_id === $request->user()->id,
            'text' => $m->text,
            'time' => $m->created_at->format('H:i'),
        ]);

        return Inertia::render('Admin/Chat', [
            'chats' => Chat::query()->with('user:id,name,phone')->orderByDesc('last_at')->get()->map(fn (Chat $c) => [
                'id' => $c->id,
                'title_ar' => $c->title_ar,
                'title_en' => $c->title_en,
                'last_message' => $c->last_message,
                'last_at' => $c->last_at?->diffForHumans(),
                'unread' => $c->unread,
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

        return response()->json([
            'id' => $message->id,
            'text' => $message->text,
            'from_me' => true,
            'time' => $message->created_at->format('H:i'),
        ]);
    }
}
