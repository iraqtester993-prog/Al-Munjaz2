<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AdminDashboardController extends Controller
{
    public function index(Request $request)
    {
        $today = today();
        $statusCounts = array_fill_keys(Order::STATUSES, 0);
        $recordedStatuses = Order::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)
            ->all();
        $statusCounts = array_replace($statusCounts, $recordedStatuses);

        $ordersCount = (int) array_sum($statusCounts);
        $orderValue = (int) Order::query()->sum('price');
        $deliveredValue = (int) Order::query()->where('status', 'delivered')->sum('price');
        // The order fee is the source of truth for the platform's delivery fee,
        // while transactions represent the later accounting movement.
        $fees = (int) Order::query()->sum('fee');
        $merchantCount = Tenant::query()->where('kind', 'merchant')->count();
        $courierRoles = ['courier', 'pickup_courier', 'delivery_courier'];
        $courierCount = User::query()->whereIn('role', $courierRoles)->count();
        $onlineCouriers = User::query()
            ->whereIn('role', $courierRoles)
            ->where('status', 'active')
            ->where('is_online', true)
            ->count();

        $merchantBalance = (int) Tenant::query()
            ->where('kind', 'merchant')
            ->sum('wallet_balance');
        $courierBudget = (int) Wallet::query()
            ->whereHas('user', fn ($query) => $query->whereIn('role', $courierRoles))
            ->sum('budget');
        $collected = (int) Transaction::query()
            ->where('type', 'collected')
            ->where('direction', 1)
            ->sum('amount');

        $week = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = $today->copy()->subDays($i);
            $week[] = [
                'label' => $d->translatedFormat('D'),
                'count' => Order::query()->whereDate('date', $d)->count(),
            ];
        }

        $recentOrders = Order::query()
            ->with(['courier:id,name', 'merchant:id,name,shop_name', 'tenant:id,name'])
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (Order $o) => [
            'id' => $o->id,
            'track_no' => $o->track_no,
            'customer_name_ar' => $o->customer_name_ar,
            'customer_name_en' => $o->customer_name_en,
            'phone' => $o->phone,
            'price' => $o->price,
            'status' => $o->status,
            'date' => $o->date->toDateString(),
            'source' => $o->source,
            'merchant_name' => $o->merchant?->name ?? $o->tenant?->name,
            'courier_name' => $o->courier?->name,
        ]);

        $recentNotifs = Notification::query()->latest('id')->limit(5)->get()->map(fn (Notification $n) => [
            'id' => $n->id,
            'title' => $n->titleFor(),
            'body' => $n->bodyFor(),
            'read' => $n->read_at !== null,
            'time' => $n->created_at->diffForHumans(),
        ]);

        $merchantStats = Order::query()
            ->selectRaw('COALESCE(merchant_id, created_by) as merchant_user_id')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'delivered' THEN price ELSE 0 END), 0) as collected")
            ->whereRaw('COALESCE(merchant_id, created_by) IS NOT NULL')
            ->groupBy('merchant_user_id');

        $topMerchants = User::query()
            ->leftJoinSub($merchantStats, 'merchant_stats', function ($join) {
                $join->on('merchant_stats.merchant_user_id', '=', 'users.id');
            })
            ->where('users.role', 'merchant')
            ->select([
                'users.id',
                'users.name',
                'users.phone',
                'users.shop_name',
            ])
            ->selectRaw('COALESCE(merchant_stats.orders_count, 0) as orders_count')
            ->selectRaw('COALESCE(merchant_stats.collected, 0) as collected')
            ->orderByDesc('orders_count')
            ->orderBy('users.name')
            ->limit(4)
            ->get()
            ->map(fn (User $merchant) => [
                'id' => $merchant->id,
                'name' => $merchant->name,
                'shop_name' => $merchant->shop_name,
                'phone' => $merchant->phone,
                'orders' => (int) $merchant->orders_count,
                'collected' => (int) $merchant->collected,
            ]);

        $todayOrders = Order::query()->whereDate('date', $today)->count();
        $attentionCount = ($statusCounts['pending'] ?? 0) + ($statusCounts['approved'] ?? 0);
        $deliveryRate = $ordersCount > 0
            ? (int) round((($statusCounts['delivered'] ?? 0) / $ordersCount) * 100)
            : 0;

        return Inertia::render('Admin/Dashboard', [
            'kpis' => [
                'orders' => $ordersCount,
                'pending' => $statusCounts['pending'] ?? 0,
                'courier' => $statusCounts['courier'] ?? 0,
                'delivered' => $statusCounts['delivered'] ?? 0,
                'value' => $orderValue,
                'deliveredValue' => $deliveredValue,
                'fees' => $fees,
                'merchants' => $merchantCount,
                'couriers' => $courierCount,
                'users' => User::query()->count(),
                'unreadNotifs' => Notification::query()->whereNull('read_at')->count(),
            ],
            'operations' => [
                'todayOrders' => $todayOrders,
                'attentionCount' => $attentionCount,
                'onlineCouriers' => $onlineCouriers,
                'deliveryRate' => $deliveryRate,
            ],
            'financials' => [
                'value' => $orderValue,
                'deliveredValue' => $deliveredValue,
                'fees' => $fees,
                'merchantBalance' => $merchantBalance,
                'courierBudget' => $courierBudget,
                'collected' => $collected,
            ],
            'statusCounts' => $statusCounts,
            'week' => $week,
            'recentOrders' => $recentOrders,
            'recentNotifs' => $recentNotifs,
            'topMerchants' => $topMerchants,
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
