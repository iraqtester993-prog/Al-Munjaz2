<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AdminDashboardController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::query()->with('courier:id,name')->get();
        $users = User::query()->get();
        $tenants = Tenant::query()->with('plan')->get();

        $statusCounts = [];
        foreach (Order::STATUSES as $status) {
            $statusCounts[$status] = $orders->where('status', $status)->count();
        }

        $today = today();
        $fees = Transaction::query()->where('type', 'delivery_fee')->sum('amount');

        $week = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = $today->copy()->subDays($i);
            $week[] = [
                'label' => $d->translatedFormat('D'),
                'count' => Order::query()->whereDate('date', $d)->count(),
            ];
        }

        $recentOrders = $orders->sortByDesc('id')->take(8)->values()->map(fn (Order $o) => [
            'id' => $o->id,
            'track_no' => $o->track_no,
            'customer_name_ar' => $o->customer_name_ar,
            'phone' => $o->phone,
            'price' => $o->price,
            'status' => $o->status,
            'date' => $o->date->toDateString(),
            'source' => $o->source,
        ]);

        $recentNotifs = Notification::query()->latest('id')->limit(5)->get()->map(fn (Notification $n) => [
            'title' => $n->titleFor(),
            'body' => $n->bodyFor(),
            'read' => $n->read_at !== null,
            'time' => $n->created_at->diffForHumans(),
        ]);

        return Inertia::render('Admin/Dashboard', [
            'kpis' => [
                'orders' => $orders->count(),
                'pending' => $statusCounts['pending'] ?? 0,
                'courier' => $statusCounts['courier'] ?? 0,
                'delivered' => $statusCounts['delivered'] ?? 0,
                'value' => $orders->sum('price'),
                'deliveredValue' => $orders->where('status', 'delivered')->sum('price'),
                'fees' => $fees,
                'merchants' => $tenants->where('kind', 'merchant')->count(),
                'couriers' => $tenants->where('kind', 'courier')->count(),
                'users' => $users->count(),
                'unreadNotifs' => Notification::query()->whereNull('read_at')->count(),
            ],
            'statusCounts' => $statusCounts,
            'week' => $week,
            'recentOrders' => $recentOrders,
            'recentNotifs' => $recentNotifs,
        ]);
    }

    public function finance(Request $request)
    {
        $transactions = Transaction::query()
            ->with('user:id,name')
            ->latest('date')
            ->limit(200)
            ->get()
            ->map(fn (Transaction $tx) => [
                'id' => $tx->id,
                'type' => $tx->type,
                'amount' => $tx->amount,
                'direction' => $tx->direction,
                'ref' => $tx->ref,
                'date' => $tx->date->toDateString(),
                'note' => $tx->note,
                'user' => $tx->user?->name,
            ]);

        return Inertia::render('Admin/Finance', [
            'transactions' => $transactions,
            'summary' => [
                'settlements' => Transaction::query()->where('type', 'settlement')->where('direction', 1)->sum('amount'),
                'withdrawals' => Transaction::query()->where('type', 'withdrawal')->sum('amount'),
                'fees' => Transaction::query()->where('type', 'delivery_fee')->sum('amount'),
                'collected' => Transaction::query()->where('type', 'collected')->where('direction', 1)->sum('amount'),
            ],
        ]);
    }

    public function notifications(Request $request)
    {
        $notifications = Notification::query()
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Notification $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->titleFor(),
                'body' => $n->bodyFor(),
                'read' => $n->read_at !== null,
                'time' => $n->created_at->diffForHumans(),
            ]);

        return Inertia::render('Admin/Notifications', ['notifications' => $notifications]);
    }
}
